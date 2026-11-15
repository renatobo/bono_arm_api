<?php
namespace BonoArmApi\ARMember;

use BonoArmApi\Infrastructure\Renewal_Repository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adapter for the inspected ARMember Pro 8.1.1 Edit Member renewal transition. */
class Renewal_Gateway {
	private $repository;

	public function __construct( Renewal_Repository $repository ) {
		$this->repository = $repository;
	}

	private function available() {
		global $arm_members_class, $arm_payment_gateways, $arm_global_settings, $arm_subscription_plans, $arm_restriction;
		if ( ! defined( 'MEMBERSHIP_VERSION' ) || '8.1.1' !== MEMBERSHIP_VERSION || ! class_exists( 'ARM_Plan' ) || ! $this->repository->armember_available() ) {
			return false;
		}
		foreach ( array( 'arm_get_user_meta', 'arm_update_user_meta', 'arm_set_member_status', 'arm_maybe_unserialize' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}
		foreach ( array(
			array( $arm_members_class, 'arm_get_next_due_date' ),
			array( $arm_payment_gateways, 'arm_save_payment_log' ),
			array( $arm_payment_gateways, 'arm_get_global_currency' ),
			array( $arm_global_settings, 'arm_get_all_global_settings' ),
			array( $arm_subscription_plans, 'get_member_current_subscription_plan_details' ),
			array( $arm_restriction, 'arm_check_content_hasaccess' ),
		) as $callback ) {
			if ( ! is_callable( $callback ) ) {
				return false;
			}
		}
		return true;
	}

	public function preview( $user_id, array $command ) {
		global $arm_members_class, $arm_payment_gateways, $arm_global_settings, $arm_subscription_plans, $arm_restriction;
		if ( ! $this->available() ) {
			return $this->error( 'armember_unavailable', 503 );
		}
		$plan_id = $command['plan_id'];
		$user    = get_user_by( 'ID', $user_id );
		$status  = $this->repository->member_status( $user_id );
		if ( ! $user || ! $status ) {
			return $this->error( 'member_not_found', 404 );
		}
		$assigned = (array) arm_get_user_meta( $user_id, 'arm_user_plan_ids', true );
		$data     = arm_get_user_meta( $user_id, 'arm_user_plan_' . $plan_id, true );
		if ( ! in_array( $plan_id, array_map( 'intval', $assigned ), true ) || ! is_array( $data ) || empty( $data['arm_current_plan_detail'] ) ) {
			return $this->error( 'plan_not_found', 404 );
		}
		$plan = new \ARM_Plan( 0 );
		$plan->init( (object) $data['arm_current_plan_detail'] );
		$suspended    = array_filter( (array) arm_get_user_meta( $user_id, 'arm_user_suspended_plan_ids', true ) );
		$primary      = (int) $status['arm_primary_status'];
		$secondary    = (int) $status['arm_secondary_status'];
		$requirements = array(
			'plan_inactive'           => $plan->exists() && $plan->is_active(),
			'plan_not_recurring'      => $plan->is_recurring() && ! $plan->is_free() && ! $plan->is_lifetime(),
			'payment_mode_not_manual' => 'manual_subscription' === ( $data['arm_payment_mode'] ?? '' ),
			'subscription_cancelled'  => empty( $data['arm_cencelled_plan'] ),
			'plan_change_pending'     => empty( $data['arm_change_plan_to'] ),
			'account_restricted'      => in_array( $primary, array( 1, 2 ), true ) && in_array( $secondary, array( 0, 5 ), true ),
			'administrator_member'    => ! user_can( $user, 'manage_options' ),
		);
		foreach ( $requirements as $reason => $supported ) {
			if ( ! $supported ) {
				return $this->unsupported( $reason );
			}
		}
		// Never clear an ambiguous administrative suspension or another plan's restriction.
		// A just-added matching sponsor payment must not hide the preceding payment failure.
		$latest = $this->repository->latest_payment( $user_id, $plan_id, $command['payment']['payment_log_id'] ?? 0 );
		if ( ! empty( $data['arm_grace_period_action'] ) && 'failed_payment' !== $data['arm_grace_period_action'] ) {
			return $this->unsupported( 'unrelated_grace_action' );
		}
		if ( 5 === $secondary && array_diff( array_map( 'intval', $suspended ), array( $plan_id ) ) ) {
			return $this->unsupported( 'other_plan_restriction' );
		}

		foreach ( array( 'arm_start_plan', 'arm_next_due_payment', 'arm_completed_recurring', 'arm_payment_cycle' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || ! preg_match( '/^\d+$/D', (string) $data[ $field ] ) ) {
				return $this->unsupported( 'invalid_schedule_field', array( 'field' => $field ) );
			}
		}
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- ARMember stores local wall-clock timestamps.
		$now = current_time( 'timestamp' );
		if ( (int) $data['arm_start_plan'] > $now ) {
			return $this->unsupported( 'subscription_not_started' );
		}
		if ( in_array( $plan_id, array_map( 'intval', $suspended ), true ) ) {
			$confirmed = 'nonpayment' === ( $command['suspension_reason'] ?? '' );
			if ( $confirmed && (int) $data['arm_next_due_payment'] > $now ) {
				return $this->unsupported( 'confirmed_suspension_not_overdue' );
			}
			// Native automatic suspension clears its grace reason; later payments can also
			// supersede the failed log. The operator must confirm this ambiguous case.
			if ( ! $confirmed && 'failed_payment' !== ( $data['arm_grace_period_action'] ?? '' ) && ( ! $latest || 'failed' !== $latest['arm_transaction_status'] ) ) {
				return $this->unsupported( 'suspension_reason_unverified' );
			}
		}
		$cycle = (int) $data['arm_payment_cycle'];
		if ( ! empty( $plan->options['payment_cycles'] ) && ! isset( $plan->options['payment_cycles'][ $cycle ] ) ) {
			return $this->unsupported( 'payment_cycle_missing' );
		}
		$recurring = $plan->prepare_recurring_data( $cycle );
		$count     = (int) $data['arm_completed_recurring'] + 1;
		if ( ! in_array( $recurring['period'] ?? '', array( 'D', 'M', 'Y' ), true ) || empty( $recurring['interval'] ) || (int) $recurring['interval'] < 1 ) {
			return $this->unsupported( 'billing_interval_invalid' );
		}
		if ( 'infinite' !== ( $recurring['rec_time'] ?? '' ) ) {
			if ( ! is_numeric( $recurring['rec_time'] ?? null ) ) {
				return $this->unsupported( 'recurrence_limit_invalid' );
			}
			if ( $count > (int) $recurring['rec_time'] ) {
				return $this->unsupported( 'recurrence_exhausted' );
			}
		}
		if ( (int) $data['arm_start_plan'] <= 0 || (int) $data['arm_next_due_payment'] <= (int) $data['arm_start_plan'] ) {
			return $this->unsupported( 'schedule_dates_invalid' );
		}
		$billing = $plan->options['recurring']['manual_billing_start'] ?? '';
		if ( 'transaction_day' !== $billing && ( ! ctype_digit( (string) $billing ) || (int) $billing < 1 || (int) $billing > 31 ) ) {
			return $this->unsupported( 'billing_day_invalid' );
		}
		$after                            = $data;
		$after['arm_completed_recurring'] = $count;
		$after['arm_next_due_payment']    = $this->calculate_due( $user_id, $plan_id, $after );
		$after['arm_user_gateway']        = 'manual';
		$after['arm_is_user_in_grace']    = 0;
		$after['arm_grace_period_end']    = '';
		$after['arm_grace_period_action'] = '';
		if ( (int) $after['arm_next_due_payment'] <= (int) $data['arm_next_due_payment'] ) {
			return $this->unsupported( 'due_date_not_advanced' );
		}
		if ( (int) $after['arm_next_due_payment'] <= $now || ( ! empty( $after['arm_expire_plan'] ) && (int) $after['arm_expire_plan'] <= $now ) ) {
			return $this->error( 'still_overdue', 422, array( 'projected_next_due_at' => $this->date( $after['arm_next_due_payment'] ) ) );
		}
		$settings = $arm_global_settings->arm_get_all_global_settings();
		$general  = $settings['general_settings'] ?? array();
		$quote    = $this->quote( $user_id, $recurring['amount'], $general );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		$currency = strtoupper( $arm_payment_gateways->arm_get_global_currency() );
		$payment  = $command['payment'];
		$record   = null;
		if ( 'create' === $payment['mode'] ) {
			if ( $currency !== $payment['currency'] || $quote['amount'] !== $this->money( $payment['amount'], $quote['decimals'] ) || (float) $payment['amount'] !== (float) $quote['amount'] ) {
				return $this->error( 'payment_mismatch', 422 );
			}
			if ( $this->repository->reference_exists( $payment['reference'] ) ) {
				return $this->error( 'payment_already_applied', 409 );
			}
			$reference = $payment['reference'];
		} else {
			if ( $this->repository->funding_reserved( $payment['payment_log_id'] ) ) {
				return $this->error( 'payment_already_applied', 409 );
			}
			$record = $this->repository->payment( $payment['payment_log_id'] );
			if ( ! $record ) {
				return $this->error( 'payment_not_found', 404 );
			}
			$extra   = $this->extra( $record['arm_extra_vars'] );
			$purpose = $extra['bono_sponsored_renewal'] ?? array();
			// An admin can record this exact purpose in Add Manual Payment's Note field.
			if ( ! $purpose && ! empty( $extra['note'] ) ) {
				$purpose = json_decode( $extra['note'], true );
			}
			if ( ! $this->matches_payment( $record, $user_id, $plan_id, $quote['amount'], $currency ) || ! is_array( $purpose ) || (int) ( $purpose['completed_cycles'] ?? 0 ) !== $count || (int) ( $purpose['start_plan'] ?? 0 ) !== (int) $data['arm_start_plan'] || ( ! is_string( $purpose['sponsor_name'] ?? null ) || '' === trim( $purpose['sponsor_name'] ) ) || empty( $record['arm_transaction_id'] ) || '-' === $record['arm_transaction_id'] ) {
				return $this->error( 'payment_mismatch', 422 );
			}
			$reference = $record['arm_transaction_id'];
		}
		$remaining = array_values( array_diff( $suspended, array( $plan_id ) ) );
		if ( ! $this->access( $user_id, $plan_id, $after, $remaining ) ) {
			return $this->unsupported( 'entitlement_denied' );
		}
		return array(
			'fingerprint'      => hash( 'sha256', wp_json_encode( array( $status, $data, $assigned, $suspended, $record, $latest, $quote, $general, $currency, $user->roles, get_option( 'timezone_string' ), get_option( 'gmt_offset' ) ) ) ),
			'payment_identity' => 'reference:' . $reference,
			'cycle_identity'   => $data['arm_start_plan'] . ':' . $cycle . ':' . $count,
			'cycle'            => $count,
			'data'             => $after,
			'record'           => $record,
			'snapshot'         => array(
				// Whitelist operational state: never journal gateway credentials or payment tokens.
				'plan'      => array_intersect_key( $data, array_fill_keys( array( 'arm_start_plan', 'arm_expire_plan', 'arm_payment_cycle', 'arm_completed_recurring', 'arm_next_due_payment', 'arm_payment_mode', 'arm_user_gateway', 'arm_is_user_in_grace', 'arm_grace_period_end', 'arm_grace_period_action' ), true ) ),
				'suspended' => $suspended,
				'assigned'  => $assigned,
				'roles'     => $user->roles,
			),
			'suspended'        => $remaining,
			'quote'            => $quote,
			'public'           => array(
				'user_id'           => $user_id,
				'plan_id'           => $plan_id,
				'cycles_to_advance' => 1,
				'before'            => $this->state( $data, $primary, $secondary ),
				'after'             => array_merge( $this->state( $after, 1, 0 ), array( 'entitlement_active' => true ) ),
				'payment'           => array(
					'mode'           => $payment['mode'],
					'will_create'    => 'create' === $payment['mode'],
					'reference'      => $reference,
					'amount'         => $quote['amount'],
					'currency'       => $currency,
					'payment_log_id' => $record ? (int) $record['arm_log_id'] : null,
				),
				'notifications'     => array( 'policy' => 'suppressed' ),
			),
		);
	}

	/** Run the original calculator against isolated reads, never temporary database writes. */
	public function calculate_due( $user_id, $plan_id, array $projected ) {
		global $arm_members_class;
		$reader = static function ( $value, $id, $key ) use ( $user_id, $plan_id, $projected ) {
			return $id === $user_id && 'arm_user_plan_' . $plan_id === $key ? array( $projected ) : $value;
		};
		add_filter( 'get_user_metadata', $reader, PHP_INT_MAX, 3 );
		try {
			return $arm_members_class->arm_get_next_due_date( $user_id, $plan_id, false, $projected['arm_payment_cycle'] );
		} finally {
			remove_filter( 'get_user_metadata', $reader, PHP_INT_MAX );
		}
	}

	private function access( $user_id, $plan_id, $data, $suspended ) {
		global $arm_subscription_plans, $arm_restriction;
		$reader = static function ( $value, $id, $key ) use ( $user_id, $plan_id, $data, $suspended ) {
			if ( $id === $user_id && 'arm_user_plan_' . $plan_id === $key ) {
				return array( $data );
			}
			return $id === $user_id && 'arm_user_suspended_plan_ids' === $key ? array( $suspended ) : $value;
		};
		$actor  = wp_get_current_user();
		add_filter( 'get_user_metadata', $reader, PHP_INT_MAX, 3 );
		try {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated entitlement read, without firing set_current_user hooks during preview.
			$GLOBALS['current_user'] = get_user_by( 'ID', $user_id );
			$plans                   = $arm_subscription_plans->get_member_current_subscription_plan_details( $user_id, $plan_id );
			return isset( $plans[ $plan_id ] ) && $arm_restriction->arm_check_content_hasaccess( array( $plan_id ), 'show' );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the authenticated actor in finally.
			$GLOBALS['current_user'] = $actor;
			remove_filter( 'get_user_metadata', $reader, PHP_INT_MAX );
		}
	}

	public function execute( $user_id, $command, $projection, $operation_id, $checkpoint ) {
		global $arm_payment_gateways, $arm_members_class;
		$plan_id = $command['plan_id'];
		$payment = $command['payment'];
		$inserts = 0;
		$guard   = static function ( $query ) use ( &$inserts, $payment ) {
			global $wpdb;
			if ( preg_match( '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $wpdb->prefix . 'arm_payment_log', '/' ) . '`?\s*\(/i', $query ) ) {
				++$inserts;
				// Block the vendor primitive’s unescaped retry SQL and extra payment insertions.
				if ( $inserts > ( 'create' === $payment['mode'] ? 1 : 0 ) || false === strpos( $query, '`' . $wpdb->prefix . 'arm_payment_log`' ) ) {
					return '';
				}
			}
			return $query;
		};
		add_filter( 'query', $guard, PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( $this, 'suppress_mail' ), PHP_INT_MAX );
		try {
			$checkpoint( 'cycle', 'intent' );
			arm_update_user_meta( $user_id, 'arm_user_plan_' . $plan_id, $projection['data'] );
			arm_update_user_meta( $user_id, 'arm_user_suspended_plan_ids', $projection['suspended'] );
			$checkpoint( 'cycle', 'written' );
			$checkpoint( 'payment', 'intent' );
			if ( 'create' === $payment['mode'] ) {
				$metadata = array(
					'sponsor_name'     => $payment['sponsor_name'],
					'operation_id'     => $operation_id,
					'actor_id'         => get_current_user_id(),
					'completed_cycles' => $projection['cycle'],
					'start_plan'       => $projection['data']['arm_start_plan'],
					'paid_at'          => $payment['paid_at'],
				);
				$log_id   = $arm_payment_gateways->arm_save_payment_log(
					array(
						'arm_user_id'                  => $user_id,
						'arm_plan_id'                  => $plan_id,
						'arm_payment_gateway'          => 'manual',
						'arm_payment_type'             => 'subscription',
						'arm_transaction_payment_type' => 'subscription',
						'arm_payment_mode'             => 'manual_subscription',
						'arm_transaction_status'       => 'success',
						'arm_transaction_id'           => $payment['reference'],
						'arm_amount'                   => $projection['public']['payment']['amount'],
						'arm_currency'                 => $payment['currency'],
						'arm_payment_date'             => wp_date( 'Y-m-d H:i:s', strtotime( $payment['paid_at'] ), wp_timezone() ),
						'arm_created_date'             => current_time( 'mysql' ),
						'arm_extra_vars'               => maybe_serialize(
							array_merge(
								$projection['quote']['extra'],
								array(
									'bono_sponsored_renewal' => $metadata,
									'note'      => wp_json_encode( $metadata ),
									'manual_by' => $payment['sponsor_name'],
								)
							)
						),
					)
				);
			} else {
				$log_id = $payment['payment_log_id'];
			}
			$checkpoint(
				'payment',
				array(
					'payment_log_id' => (int) $log_id,
					'created'        => 'create' === $payment['mode'],
				)
			);
			$checkpoint( 'activation', 'intent' );
			if ( 1 !== $projection['public']['before']['primary_status'] || 0 !== $projection['public']['before']['secondary_status'] ) {
				arm_set_member_status( $user_id, 1 );
			}
			$checkpoint( 'activation', 'written' );
			clean_user_cache( $user_id );
			$actual           = arm_get_user_meta( $user_id, 'arm_user_plan_' . $plan_id, true );
			$status           = $this->repository->member_status( $user_id );
			$record           = $this->repository->payment( $log_id );
			$payment_verified = $this->verify_payment_details( $record, $command, $projection, $operation_id );
			if ( ! $payment_verified || $inserts > ( 'create' === $payment['mode'] ? 1 : 0 ) || $actual !== $projection['data'] || ! is_array( $status ) || get_user_by( 'ID', $user_id )->roles !== $projection['snapshot']['roles'] || arm_get_user_meta( $user_id, 'arm_user_plan_ids', true ) !== $projection['snapshot']['assigned'] || array_values( (array) arm_get_user_meta( $user_id, 'arm_user_suspended_plan_ids', true ) ) !== $projection['suspended'] || 1 !== (int) $status['arm_primary_status'] || 0 !== (int) $status['arm_secondary_status'] || ! $this->matches_payment( $record, $user_id, $plan_id, $projection['public']['payment']['amount'], $projection['public']['payment']['currency'] ) || (int) $record['arm_invoice_id'] < 1 || $record['arm_transaction_id'] !== $projection['public']['payment']['reference'] || (int) $arm_members_class->arm_get_next_due_date( $user_id, $plan_id, false, $actual['arm_payment_cycle'] ) !== (int) $actual['arm_next_due_payment'] || ! $this->access( $user_id, $plan_id, $actual, $projection['suspended'] ) ) {
				return $this->error( 'renewal_needs_reconciliation', 503 );
			}
			return array(
				'user_id'         => $user_id,
				'plan_id'         => $plan_id,
				'cycles_advanced' => 1,
				'before'          => $projection['public']['before'],
				'after'           => array_merge( $this->state( $actual, 1, 0 ), array( 'entitlement_active' => true ) ),
				'payment'         => array(
					'mode'           => $payment['mode'],
					'created'        => 'create' === $payment['mode'],
					'payment_log_id' => (int) $log_id,
					'invoice_id'     => (int) $record['arm_invoice_id'],
					'reference'      => $record['arm_transaction_id'],
					'amount'         => $projection['public']['payment']['amount'],
					'currency'       => $record['arm_currency'],
				),
				'notifications'   => array( 'policy' => 'suppressed' ),
			);
		} finally {
			remove_filter( 'pre_wp_mail', array( $this, 'suppress_mail' ), PHP_INT_MAX );
			remove_filter( 'query', $guard, PHP_INT_MAX );
		}
	}

	public function suppress_mail( $value ) {
		// Limit suppression to synchronous ARMember mailers reached by this operation.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Inspect class names only; no arguments or output.
			if ( in_array( strtolower( $frame['class'] ?? '' ), array( 'arm_global_settings', 'arm_manage_communication' ), true ) ) {
				return false;
			}
		}
		return $value;
	}

	private function verify_payment_details( $record, $command, $projection, $operation_id ) {
		if ( ! $record ) {
			return false;
		}
		if ( 'existing' === $command['payment']['mode'] ) {
			return $record === $projection['record'];
		}
		$extra   = $this->extra( $record['arm_extra_vars'] );
		$purpose = $extra['bono_sponsored_renewal'] ?? array();
		return ( $purpose['operation_id'] ?? '' ) === $operation_id
			&& ( $purpose['sponsor_name'] ?? '' ) === $command['payment']['sponsor_name']
			&& (int) ( $purpose['completed_cycles'] ?? 0 ) === $projection['cycle']
			&& wp_date( 'Y-m-d H:i:s', strtotime( $command['payment']['paid_at'] ), wp_timezone() ) === $record['arm_payment_date'];
	}

	private function quote( $user_id, $amount, $settings ) {
		$decimals = (int) ( $settings['arm_currency_decimal_digit'] ?? 2 );
		if ( $decimals < 0 || $decimals > 6 || ! is_numeric( $amount ) || (float) $amount <= 0 ) {
			return $this->unsupported( 'cycle_amount_invalid' );
		}
		$tax = 0;
		if ( ! empty( $settings['enable_tax'] ) ) {
			if ( 'common_tax' === ( $settings['tax_type'] ?? '' ) ) {
				$tax = $settings['tax_amount'] ?? 0;
			} elseif ( 'country_tax' === ( $settings['tax_type'] ?? '' ) ) {
				$country = get_user_meta( $user_id, $settings['country_tax_field'] ?? 'country', true );
				$names   = arm_maybe_unserialize( $settings['arm_tax_country_name'] ?? array() );
				$rates   = arm_maybe_unserialize( $settings['arm_country_tax_val'] ?? array() );
				$index   = array_search( $country, (array) $names, true );
				$tax     = false === $index ? ( $settings['arm_country_tax_default_val'] ?? 0 ) : ( $rates[ $index ] ?? null );
			} else {
				return $this->unsupported( 'tax_configuration_unsupported' );
			}
		}
		if ( ! is_numeric( $tax ) || $tax < 0 || $tax > 100 ) {
			return $this->unsupported( 'tax_rate_invalid' );
		}
		$tax_amount = empty( $settings['arm_tax_include_exclude_flag'] ) ? (float) $amount * $tax / 100 : (float) $amount - (float) $amount / ( 1 + $tax / 100 );
		$total      = empty( $settings['arm_tax_include_exclude_flag'] ) ? (float) $amount + (float) $this->money( $tax_amount, $decimals ) : $amount;
		return array(
			'amount'   => $this->money( $total, $decimals ),
			'decimals' => $decimals,
			'extra'    => array(
				'plan_amount'    => $this->money( $amount, $decimals ),
				'tax_percentage' => $tax,
				'tax_amount'     => $this->money( $tax_amount, $decimals ),
				'paid_amount'    => $this->money( $total, $decimals ),
			),
		);
	}

	private function matches_payment( $record, $user_id, $plan_id, $amount, $currency ) {
		return $record && (int) $record['arm_invoice_id'] > 0 && (int) $record['arm_user_id'] === $user_id && (int) $record['arm_plan_id'] === $plan_id && 'manual' === $record['arm_payment_gateway'] && 'manual_subscription' === $record['arm_payment_mode'] && 'success' === $record['arm_transaction_status'] && (float) $record['arm_amount'] === (float) $amount && $record['arm_currency'] === $currency && empty( $record['arm_is_post_payment'] ) && empty( $record['arm_is_gift_payment'] );
	}

	private function money( $amount, $decimals ) {
		return number_format( (float) $amount, $decimals, '.', '' );
	}

	private function extra( $value ) {
		$value = arm_maybe_unserialize( $value );
		return is_array( $value ) ? $value : array();
	}

	private function state( $data, $primary, $secondary ) {
		$result = array(
			'primary_status'   => $primary,
			'secondary_status' => $secondary,
			'completed_cycles' => (int) $data['arm_completed_recurring'],
			'next_due_at'      => $this->date( $data['arm_next_due_payment'] ),
		);
		if ( ! empty( $data['arm_expire_plan'] ) ) {
			$result['expires_at'] = $this->date( $data['arm_expire_plan'] );
		}
		return $result;
	}

	private function date( $timestamp ) {
		// ARMember stores local wall-clock timestamps and compares current_time('timestamp').
		return ( new \DateTimeImmutable( gmdate( 'Y-m-d H:i:s', (int) $timestamp ), wp_timezone() ) )->format( DATE_RFC3339 );
	}

	private function unsupported( $reason, $details = array() ) {
		return $this->error( 'unsupported_subscription', 422, array_merge( array( 'reason' => $reason ), $details ) );
	}

	private function error( $suffix, $status, $details = array() ) {
		return new WP_Error( 'bono_arm_api_' . $suffix, __( 'The subscription or payment does not meet the sponsored renewal requirements.', 'bono-arm-api' ), array_merge( array( 'status' => $status ), $details ) );
	}
}
