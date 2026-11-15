<?php
namespace BonoArmApi\ARMember;

use BonoArmApi\Infrastructure\Renewal_Repository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Badge_Gateway {
	public function handle( $method, $user_id, $badge_id ) {
		global $arm_members_badges;
		foreach ( array( 'arm_get_all_badges', 'arm_get_single_badge' ) as $required_method ) {
			if ( ! is_callable( array( $arm_members_badges, $required_method ) ) ) {
				return $this->unavailable();
			}
		}
		try {
			if ( 'GET' === $method ) {
				return $this->execute( $method, $user_id, $badge_id, $arm_members_badges );
			}
			$repository = new Renewal_Repository();
			$lock       = $repository->lock( 'member:' . (int) $user_id );
			if ( ! $lock ) {
				return $this->conflict();
			}
			try {
				return $this->execute( $method, $user_id, $badge_id, $arm_members_badges );
			} finally {
				$repository->unlock( $lock );
			}
		} catch ( \Throwable $exception ) {
			return $this->unavailable();
		}
	}

	private function execute( $method, $user_id, $badge_id, $native ) {
		$user_id = (int) $user_id;
		if ( $user_id && ( ! get_userdata( $user_id ) || ( is_multisite() && ! is_user_member_of_blog( $user_id ) ) ) ) {
			return new WP_Error( 'bono_arm_api_member_not_found', __( 'Member not found.', 'bono-arm-api' ), array( 'status' => 404 ) );
		}
		if ( 'GET' === $method ) {
			$assignments = $user_id ? $this->assignments( get_user_meta( $user_id, 'arm_achievements', true ) ) : array();
			if ( is_wp_error( $assignments ) ) {
				return $assignments;
			}
			$badges      = array();
			$definitions = $native->arm_get_all_badges();
			foreach ( $definitions ? (array) $definitions : array() as $definition ) {
				$definition = (array) $definition;
				$id         = (int) $definition['arm_badges_id'];
				if ( $user_id && empty( $assignments[ $id ] ) ) {
					continue;
				}
				$badges[] = $this->badge( $definition );
			}
			return array( 'badges' => $badges );
		}
		$badge_id = (int) $badge_id;
		$badge    = $native->arm_get_single_badge( $badge_id );
		if ( ! $badge || 'badge' !== ( $badge['arm_badges_type'] ?? '' ) ) {
			return new WP_Error( 'bono_arm_api_badge_not_found', __( 'Badge not found.', 'bono-arm-api' ), array( 'status' => 404 ) );
		}
		$present = 'PUT' === $method;
		// Compare-and-swap protects other awards against concurrent metadata updates.
		// Pro 8.1.1's arm_*_user_meta wrappers use these WordPress metadata functions.
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$exists = metadata_exists( 'user', $user_id, 'arm_achievements' );
			$before = get_user_meta( $user_id, 'arm_achievements', true );
			$after  = $this->assignments( $before );
			if ( is_wp_error( $after ) ) {
				return $after;
			}
			$assigned = ! empty( $after[ $badge_id ] );
			$changed  = $assigned !== $present;
			if ( false === $changed ) {
				return array(
					'user_id'  => $user_id,
					'badge_id' => $badge_id,
					'assigned' => $present,
					'changed'  => false,
				);
			}
			if ( $present ) {
				$after[ $badge_id ] = array( array( 'admin' => '1' ) );
			} else {
				unset( $after[ $badge_id ] );
			}
			// Native admin assignment stores a serialized array inside WordPress metadata.
			$value = wp_slash( maybe_serialize( $after ) );
			if ( $exists && ! empty( $before ) ) {
				$saved = update_user_meta( $user_id, 'arm_achievements', $value, $before );
			} elseif ( ! $exists ) {
				$saved = add_user_meta( $user_id, 'arm_achievements', $value, true );
			} else {
				// API writers share the member lock, including for legacy empty values.
				$saved = update_user_meta( $user_id, 'arm_achievements', $value );
			}
			if ( $saved && $after === $this->assignments( get_user_meta( $user_id, 'arm_achievements', true ) ) ) {
				return array(
					'user_id'  => $user_id,
					'badge_id' => $badge_id,
					'assigned' => $present,
					'changed'  => true,
				);
			}
		}
		return $this->conflict();
	}

	private function assignments( $value ) {
		$value = maybe_unserialize( $value );
		if ( '' === $value || false === $value || null === $value ) {
			return array();
		}
		return is_array( $value ) ? $value : new WP_Error( 'bono_arm_api_badge_state_invalid', __( 'Badge assignment data is invalid.', 'bono-arm-api' ), array( 'status' => 409 ) );
	}

	private function badge( array $definition ) {
		return array(
			'badge_id' => (int) $definition['arm_badges_id'],
			'name'     => (string) $definition['arm_badges_name'],
			'icon'     => esc_url_raw( $definition['arm_badges_icon'] ),
		);
	}

	private function conflict() {
		return new WP_Error( 'bono_arm_api_badge_update_conflict', __( 'Badge assignments changed or could not be saved. Read the current state before retrying.', 'bono-arm-api' ), array( 'status' => 409 ) );
	}

	private function unavailable() {
		return new WP_Error( 'bono_arm_api_armember_unavailable', __( 'ARMember badge support is unavailable.', 'bono-arm-api' ), array( 'status' => 503 ) );
	}
}
