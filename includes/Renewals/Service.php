<?php
namespace BonoArmApi\Renewals;

use BonoArmApi\ARMember\Renewal_Gateway;
use BonoArmApi\Infrastructure\Renewal_Repository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Service {
	private $repository;
	private $gateway;

	public function __construct( Renewal_Repository $repository, Renewal_Gateway $gateway ) {
		$this->repository = $repository;
		$this->gateway    = $gateway;
	}

	public function run( $user_id, array $command, $dry_run, $token, $key ) {
		if ( ! $this->repository->install() ) {
			return $this->error( 'armember_unavailable', 503 );
		}
		$hash = hash( 'sha256', wp_json_encode( array( $user_id, $command ) ) );
		if ( ! $dry_run ) {
			$existing = $this->repository->find_key( $key );
			if ( $existing ) {
				return $this->replay( $existing, $hash, true );
			}
		}

		if ( ! $dry_run && ! preg_match( '/^[0-9]{10}\.[a-f0-9]{64}$/D', $token ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'A commit requires the preview token.', 'bono-arm-api' ), array( 'status' => 400 ) );
		}

		// A member-wide lock also serializes account activation and shared suspension metadata.
		$lock = $this->repository->lock( 'member:' . $user_id );
		if ( ! $lock ) {
			$existing = $this->repository->unresolved( $user_id, $command['plan_id'] );
			return $this->error( 'renewal_in_progress', 409, $existing ? $existing['operation_id'] : null );
		}
		try {
			// A simultaneous request may have finished while this request acquired the lock.
			if ( ! $dry_run ) {
				$existing = $this->repository->find_key( $key );
				if ( $existing ) {
					return $this->replay( $existing, $hash );
				}
			}
			$pending = $this->repository->unresolved( $user_id, $command['plan_id'] );
			if ( $pending ) {
				return $this->error( 'renewal_needs_reconciliation', 503, $pending['operation_id'] );
			}
			try {
				$projection = $this->gateway->preview( $user_id, $command );
			} catch ( \Throwable $exception ) {
				return $this->error( 'armember_unavailable', 503 );
			}
			if ( is_wp_error( $projection ) ) {
				return $projection;
			}
			$payment_hash = hash( 'sha256', $projection['payment_identity'] );
			if ( $this->repository->payment_reserved( $payment_hash ) ) {
				return $this->error( 'payment_already_applied', 409 );
			}
			$binding = wp_json_encode( array( get_current_blog_id(), get_current_user_id(), $hash, $projection['fingerprint'] ) );
			if ( $dry_run ) {
				$expiry = time() + 15 * MINUTE_IN_SECONDS;
				return array_merge(
					$projection['public'],
					array(
						'dry_run'        => true,
						'can_execute'    => true,
						'expected_state' => $expiry . '.' . hash_hmac( 'sha256', $expiry . ':' . $binding, wp_salt( 'auth' ) ),
						'expires_at'     => gmdate( 'Y-m-d\TH:i:s\Z', $expiry ),
					)
				);
			}
			$parts = explode( '.', $token );
			if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || (int) $parts[0] < time() || (int) $parts[0] > time() + 15 * MINUTE_IN_SECONDS || ! hash_equals( hash_hmac( 'sha256', $parts[0] . ':' . $binding, wp_salt( 'auth' ) ), $parts[1] ) ) {
				return $this->error( 'state_changed', 409 );
			}
			return $this->commit( $user_id, $command, $key, $hash, $payment_hash, $projection );
		} finally {
			$this->repository->unlock( $lock );
		}
	}

	private function commit( $user_id, $command, $key, $hash, $payment_hash, $projection ) {
		// The native invoice counter is global, so serialize our invoice creation as well.
		$invoice_lock = $this->repository->lock( 'invoice' );
		if ( ! $invoice_lock ) {
			return $this->error( 'renewal_in_progress', 409 );
		}
		try {
			$id         = wp_generate_uuid4();
			$payload    = array(
				'command'      => $command,
				'before'       => $projection['public']['before'],
				'before_state' => $projection['snapshot'],
				'cycle'        => $projection['cycle'],
				'steps'        => array(),
			);
			$cycle_hash = hash( 'sha256', $user_id . ':' . $command['plan_id'] . ':' . $projection['cycle_identity'] );
			$reserved   = $this->repository->reserve(
				array(
					'operation_id'   => $id,
					'key_hash'       => hash( 'sha256', $key ),
					'command_hash'   => $hash,
					'payment_hash'   => $payment_hash,
					'funding_log_id' => 'existing' === $command['payment']['mode'] ? $command['payment']['payment_log_id'] : null,
					'cycle_hash'     => $cycle_hash,
					'user_id'        => $user_id,
					'plan_id'        => $command['plan_id'],
					'actor_id'       => get_current_user_id(),
					'state'          => 'in_progress',
					'payload'        => $payload,
				)
			);
			if ( ! $reserved ) {
				$existing = $this->repository->find_key( $key );
				if ( $existing ) {
					return $this->replay( $existing, $hash );
				}
				$conflict = $this->repository->reservation_conflict( $payment_hash, $cycle_hash, $command['payment']['payment_log_id'] ?? 0 );
				return $conflict ? $this->error( 'payment_already_applied', 409, $conflict ) : $this->error( 'armember_unavailable', 503 );
			}
			// No rollback is claimed: third-party hooks and storage engines are not transactional.
			// Persist each intent BEFORE its write; a process death leaves a blocking reservation.
			try {
				$checkpoint = function ( $step, $outcome ) use ( $id, &$payload ) {
					$payload['steps'][ $step ] = $outcome;
					if ( 'payment' === $step && is_array( $outcome ) && ! empty( $outcome['created'] ) && ! $this->repository->link_payment( $id, $outcome['payment_log_id'] ) ) {
						throw new \RuntimeException( 'Payment association failed.' );
					}
					if ( ! $this->repository->save( $id, 'in_progress', $payload ) ) {
						throw new \RuntimeException( 'Journal checkpoint failed.' );
					}
				};
				$result     = $this->gateway->execute( $user_id, $command, $projection, $id, $checkpoint );
				if ( is_wp_error( $result ) ) {
					throw new \RuntimeException( $result->get_error_code() );
				}
				$result['operation_id']      = $id;
				$result['state']             = 'completed';
				$result['idempotent_replay'] = false;
				$payload['result']           = $result;
				if ( ! $this->repository->save( $id, 'completed', $payload ) ) {
					throw new \RuntimeException( 'Completion checkpoint failed.' );
				}
				return $result;
			} catch ( \Throwable $exception ) {
				// Keep errors bounded and omit third-party exception text, which can contain secrets.
				$payload['failure'] = array(
					'exception_class' => get_class( $exception ),
					'stage'           => $payload['steps']['verification'] ?? 'write',
				);
				$this->repository->save( $id, 'needs_reconciliation', $payload );
				clean_user_cache( $user_id );
				return $this->error( 'renewal_needs_reconciliation', 503, $id, $payload['steps'] );
			}
		} finally {
			$this->repository->unlock( $invoice_lock );
		}
	}

	/** Operator attestation after external repair; never repeats vendor writes. */
	public function resolve( $id, $cycle_stands, $payment_stands ) {
		$status = $this->status( $id );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		$lock = $this->repository->lock( 'member:' . $status['user_id'] );
		if ( ! $lock ) {
			return $this->error( 'renewal_in_progress', 409, $id );
		}
		try {
			$row        = $this->repository->get( $id );
			$resolution = array(
				'actor_id'       => get_current_user_id(),
				'resolved_at'    => gmdate( 'Y-m-d H:i:s' ),
				'cycle_stands'   => $cycle_stands,
				'payment_stands' => $payment_stands,
			);
			if ( ! empty( $row['payload']['privacy_erased'] ) ) {
				return $this->error( 'operation_erased', 410, $id );
			}
			if ( 'resolved' === $row['state'] ) {
				$previous = $row['payload']['resolution'];
				return $previous['cycle_stands'] === $cycle_stands && $previous['payment_stands'] === $payment_stands ? $this->status( $id ) : $this->error( 'resolution_conflict', 409, $id );
			}
			if ( ! in_array( $row['state'], array( 'in_progress', 'needs_reconciliation' ), true ) ) {
				return $this->error( 'resolution_conflict', 409, $id );
			}
			if ( ! $this->repository->resolve( $row, $resolution ) ) {
				return $this->error( 'renewal_needs_reconciliation', 503, $id );
			}
			return $this->status( $id );
		} finally {
			$this->repository->unlock( $lock );
		}
	}

	public function status( $id ) {
		$row = '1' === get_option( 'bono_arm_api_renewals_schema' ) ? $this->repository->get( $id ) : null;
		if ( ! $row ) {
			return new WP_Error( 'bono_arm_api_operation_not_found', __( 'Renewal operation not found.', 'bono-arm-api' ), array( 'status' => 404 ) );
		}
		return array(
			'operation_id' => $row['operation_id'],
			'state'        => $row['state'],
			'user_id'      => (int) $row['user_id'],
			'plan_id'      => (int) $row['plan_id'],
			'actor_id'     => (int) $row['actor_id'],
			'created_at'   => $row['created_at'],
			'updated_at'   => $row['updated_at'],
			'details'      => $row['payload'],
		);
	}

	private function replay( $row, $hash, $check_lock = false ) {
		if ( ! hash_equals( $row['command_hash'], $hash ) ) {
			return $this->error( 'idempotency_conflict', 409, $row['operation_id'] );
		}
		if ( $check_lock && 'in_progress' === $row['state'] ) {
			$lock = $this->repository->lock( 'member:' . $row['user_id'] );
			if ( ! $lock ) {
				return $this->error( 'renewal_in_progress', 409, $row['operation_id'] );
			}
			try {
				return $this->replay( $this->repository->get( $row['operation_id'] ), $hash );
			} finally {
				$this->repository->unlock( $lock );
			}
		}
		if ( ! empty( $row['payload']['privacy_erased'] ) ) {
			return $this->error( 'operation_erased', 410, $row['operation_id'] );
		}
		if ( 'resolved' === $row['state'] ) {
			return $this->error( 'operation_resolved', 409, $row['operation_id'] );
		}
		if ( 'completed' !== $row['state'] ) {
			return $this->error( 'renewal_needs_reconciliation', 503, $row['operation_id'], $row['payload']['steps'] );
		}
		$result                      = $row['payload']['result'];
		$result['idempotent_replay'] = true;
		return $result;
	}

	private function error( $suffix, $status, $id = null, $steps = array() ) {
		return new WP_Error(
			'bono_arm_api_' . $suffix,
			__( 'The renewal could not proceed. Check the error code and operation status before retrying.', 'bono-arm-api' ),
			array(
				'status'       => $status,
				'operation_id' => $id,
				'steps'        => $steps,
			)
		);
	}
}
