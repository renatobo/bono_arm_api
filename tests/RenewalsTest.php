<?php
use BonoArmApi\Capabilities;
use BonoArmApi\Infrastructure\Renewal_Repository;

require_once __DIR__ . '/fixtures/renewal-stubs.php';

final class RenewalsTest extends WP_UnitTestCase {
	private $member;
	private $actor;
	private $body;
	private $repository;

	public static function wpSetUpBeforeClass( $factory ) {
		global $wpdb;
		// dbDelta is DDL; keep it outside each test's transaction.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}arm_members" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}arm_payment_log" );
		( new Renewal_Repository() )->install();
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}arm_members (arm_member_id bigint AUTO_INCREMENT PRIMARY KEY, arm_user_id bigint, arm_primary_status int, arm_secondary_status int)" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}arm_payment_log (
			arm_log_id bigint AUTO_INCREMENT PRIMARY KEY, arm_invoice_id bigint DEFAULT 0, arm_user_id bigint, arm_plan_id bigint,
			arm_payment_gateway varchar(50), arm_payment_type varchar(50), arm_transaction_payment_type varchar(50),
			arm_payment_mode varchar(50), arm_transaction_status varchar(50), arm_transaction_id varchar(255),
			arm_amount decimal(18,6), arm_currency varchar(10), arm_payment_date datetime, arm_created_date datetime,
			arm_extra_vars longtext, arm_first_name text, arm_last_name text, arm_token text, arm_payer_email text,
			arm_receiver_email text, arm_coupon_code text, arm_coupon_discount decimal(18,6), arm_coupon_discount_type text,
			arm_is_trial int DEFAULT 0, arm_display_log int DEFAULT 1, arm_coupon_on_each_subscriptions int DEFAULT 0,
			arm_is_post_payment int DEFAULT 0, arm_is_gift_payment int DEFAULT 0, arm_paid_post_id bigint DEFAULT 0
		)" );
	}

	public function set_up() {
		parent::set_up();
		global $wpdb, $ARMember, $arm_members_class, $arm_payment_gateways, $arm_global_settings, $arm_subscription_plans, $arm_restriction, $arm_transaction;
		if ( ! defined( 'MEMBERSHIP_VERSION' ) ) { define( 'MEMBERSHIP_VERSION', '8.1.1' ); }
		update_option( 'bono_arm_api_renewals_schema', '1' );
		set_transient( 'bono_arm_api_tables_' . get_current_blog_id(), array( 'exists' => true ), HOUR_IN_SECONDS );
		$this->repository = new Renewal_Repository();
		$this->actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Capabilities::activate();
		wp_set_current_user( $this->actor );
		$GLOBALS['bono_renew_native_activation_enabled'] = (bool) getenv( 'BONO_ARMEMBER_SOURCE' );
		$ARMember = (object) array( 'tbl_arm_payment_log' => $wpdb->prefix . 'arm_payment_log', 'tbl_arm_members' => $wpdb->prefix . 'arm_members' );
		$arm_members_class = new Bono_Renew_Members();
		$arm_transaction = new Bono_Renew_Transaction();
		$arm_payment_gateways = new class {
			public function arm_get_global_currency() { return 'USD'; }
			public function arm_save_payment_log( $data ) { global $arm_transaction; return $arm_transaction->arm_add_transaction( $data ); }
		};
		$arm_global_settings = new class {
			public $global_settings = array( 'arm_currency_decimal_digit' => 2 );
			public function arm_get_all_global_settings() { return array( 'general_settings' => $this->global_settings ); }
		};
		$arm_subscription_plans = new class {
			public function arm_default_plan_array() { return array_fill_keys( array( 'arm_current_plan_detail', 'arm_start_plan', 'arm_expire_plan', 'arm_payment_mode', 'arm_completed_recurring' ), '' ); }
			public function get_member_current_subscription_plan_details( $id, $plan ) {
				return in_array( $plan, (array) get_user_meta( $id, 'arm_user_suspended_plan_ids', true ) ) ? array() : array( $plan => get_user_meta( $id, 'arm_user_plan_' . $plan, true ) );
			}
		};
		$arm_restriction = new class {
			public function arm_check_content_hasaccess( $plans, $type ) { return ! array_diff( $plans, (array) get_user_meta( get_current_user_id(), 'arm_user_plan_ids', true ) ); }
		};
		$wpdb->insert( $wpdb->prefix . 'arm_members', array( 'arm_user_id' => $this->member, 'arm_primary_status' => 2, 'arm_secondary_status' => 0 ) );
		$year = (int) gmdate( 'Y' );
		$start = strtotime( ( $year - 1 ) . '-01-01' );
		$data = array(
			'arm_start_plan' => $start, 'arm_next_due_payment' => strtotime( $year . '-01-01' ), 'arm_completed_recurring' => 1,
			'arm_payment_cycle' => 0, 'arm_expire_plan' => '', 'arm_payment_mode' => 'manual_subscription',
			'arm_current_plan_detail' => array(
				'arm_subscription_plan_id' => 12, 'arm_subscription_plan_type' => 'recurring', 'arm_subscription_plan_status' => 1,
				'arm_subscription_plan_amount' => '30.00', 'arm_subscription_plan_options' => array(
					'payment_type' => 'subscription', 'recurring' => array( 'type' => 'Y', 'years' => 1, 'time' => 'infinite', 'manual_billing_start' => 'transaction_day' ),
				),
			),
		);
		update_user_meta( $this->member, 'arm_user_plan_12', $data );
		update_user_meta( $this->member, 'arm_user_plan_ids', array( 12 ) );
		update_user_meta( $this->member, 'arm_user_suspended_plan_ids', array() );
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_RENEWALS, true );
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION, true );
		$this->body = array( 'plan_id' => 12, 'payment' => array( 'mode' => 'create', 'reference' => 'sponsor-allocation-' . $this->member, 'sponsor_name' => "Sponsor O'Neil", 'amount' => '30.00', 'currency' => 'USD', 'paid_at' => gmdate( 'Y-m-d\T12:00:00+00:00' ) ) );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		global $ARMember, $arm_members_class, $arm_payment_gateways, $arm_global_settings, $arm_subscription_plans, $arm_restriction, $arm_transaction;
		$GLOBALS['bono_renew_native_activation_enabled'] = false;
		$ARMember = $arm_members_class = $arm_payment_gateways = $arm_global_settings = $arm_subscription_plans = $arm_restriction = $arm_transaction = null;
		parent::tear_down();
	}

	public function test_activation_toggle_alone_disables_renewal() {
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION, false );
		$this->assertSame( 503, $this->request()->get_status() );
	}

	public function test_unauthenticated_renewal_is_rejected() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request()->get_status() );
	}

	public function test_unknown_status_does_not_install_schema() {
		delete_option( 'bono_arm_api_renewals_schema' );
		$request = new WP_REST_Request( 'GET', '/bono_armember/v2/renewals/' . wp_generate_uuid4() );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );
		$this->assertFalse( get_option( 'bono_arm_api_renewals_schema' ) );
	}

	private function request( $body = null, $key = 'test-idempotency-key-1' ) {
		$request = new WP_REST_Request( 'POST', '/bono_armember/v2/members/' . $this->member . '/renewals' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $key );
		$request->set_body( wp_json_encode( $body ?? $this->body ) );
		return rest_get_server()->dispatch( $request );
	}
	private function commit_body() {
		$preview = $this->request();
		$this->assertSame( 200, $preview->get_status(), wp_json_encode( $preview->get_data() ) );
		return $this->body + array( 'dry_run' => false, 'expected_state' => $preview->get_data()['expected_state'] );
	}
	private function count_payments() { global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}arm_payment_log" ); }

	public function test_preview_then_commit_and_expired_token_replay() {
		update_user_meta( $this->member, 'arm_user_activation_key', 'pending-key' );
		$before = get_user_meta( $this->member );
		$counter = get_option( 'arm_last_invoice_id' );
		$body = $this->commit_body();
		$this->assertSame( $before, get_user_meta( $this->member ) );
		$this->assertSame( $counter, get_option( 'arm_last_invoice_id' ) );
		$this->assertSame( 0, $this->count_payments() );
		$response = $this->request( $body );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$result = $response->get_data();
		$this->assertSame( 2, $result['after']['completed_cycles'] );
		$this->assertStringContainsString( '-01-01T00:00:00', $result['after']['next_due_at'] );
		$this->assertSame( 1, $result['after']['primary_status'] );
		if ( getenv( 'BONO_ARMEMBER_SOURCE' ) ) { $this->assertSame( '', get_user_meta( $this->member, 'arm_user_activation_key', true ) ); }
		$this->assertSame( 1, $this->count_payments() );
		$this->assertSame( $this->actor, get_current_user_id() );
		$body['expected_state'] = 'expired';
		$replay = $this->request( $body );
		$this->assertSame( 200, $replay->get_status() );
		$this->assertTrue( $replay->get_data()['idempotent_replay'] );
		$this->assertSame( $result['payment']['payment_log_id'], $replay->get_data()['payment']['payment_log_id'] );
		$this->assertSame( 1, $this->count_payments() );
		$body['payment']['reference'] .= '-different';
		$this->assertSame( 'bono_arm_api_idempotency_conflict', $this->request( $body )->get_data()['code'] );
		$status = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/bono_armember/v2/renewals/' . $result['operation_id'] ) );
		$this->assertSame( 'completed', $status->get_data()['state'] );
	}

	public function test_new_key_cannot_reuse_reference_or_stale_preview() {
		$body = $this->commit_body();
		$this->assertSame( 201, $this->request( $body )->get_status() );
		$this->assertSame( 'bono_arm_api_payment_already_applied', $this->request( $body, 'another-idempotency-key' )->get_data()['code'] );
		$body['payment']['reference'] .= '-next';
		$this->assertSame( 'bono_arm_api_state_changed', $this->request( $body, 'another-idempotency-key' )->get_data()['code'] );
		$this->assertSame( 1, $this->count_payments() );
	}

	public function test_still_overdue_and_auto_debit_change_nothing() {
		$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
		$data['arm_start_plan'] = strtotime( '-5 years', $data['arm_start_plan'] );
		update_user_meta( $this->member, 'arm_user_plan_12', $data );
		$this->assertSame( 'bono_arm_api_unsupported_subscription', $this->request()->get_data()['code'] );
		$data['arm_next_due_payment'] = strtotime( '+1 year', $data['arm_start_plan'] );
		update_user_meta( $this->member, 'arm_user_plan_12', $data );
		$this->assertSame( 'bono_arm_api_still_overdue', $this->request()->get_data()['code'] );
		$data['arm_payment_mode'] = 'auto_debit_subscription';
		update_user_meta( $this->member, 'arm_user_plan_12', $data );
		$this->assertSame( 'bono_arm_api_unsupported_subscription', $this->request()->get_data()['code'] );
		$this->assertSame( 0, $this->count_payments() );
		$this->assertSame( $data, get_user_meta( $this->member, 'arm_user_plan_12', true ) );
	}

	public function test_existing_payment_reused_without_editing_it() {
		global $arm_payment_gateways;
		$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
		$id = $arm_payment_gateways->arm_save_payment_log( array( 'arm_user_id' => $this->member, 'arm_plan_id' => 12, 'arm_payment_gateway' => 'manual', 'arm_payment_mode' => 'manual_subscription', 'arm_transaction_status' => 'success', 'arm_amount' => '30.00', 'arm_currency' => 'USD', 'arm_transaction_id' => 'existing-ref', 'arm_extra_vars' => maybe_serialize( array( 'note' => wp_json_encode( array( 'sponsor_name' => 'Sponsor', 'start_plan' => $data['arm_start_plan'], 'completed_cycles' => 2 ) ) ) ) ) );
		$record = $this->repository->payment( $id );
		$this->body['payment'] = array( 'mode' => 'existing', 'payment_log_id' => (int) $id );
		$response = $this->request( $this->commit_body() );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertFalse( $response->get_data()['payment']['created'] );
		$this->assertSame( $record, $this->repository->payment( $id ) );
		$this->assertSame( 1, $this->count_payments() );
		$this->assertSame( 'bono_arm_api_payment_already_applied', $this->request( null, 'reuse-other-operation-key' )->get_data()['code'] );
	}

	public function test_partial_failure_is_durable_and_blocks_retry() {
		$body = $this->commit_body();
		$fail = static function () { throw new RuntimeException( 'Injected payment hook failure' ); };
		add_action( 'arm_after_add_transaction', $fail );
		$response = $this->request( $body );
		remove_action( 'arm_after_add_transaction', $fail );
		$this->assertSame( 503, $response->get_status() );
		$id = $response->get_data()['data']['operation_id'];
		$this->assertSame( 'needs_reconciliation', $this->repository->get( $id )['state'] );
		$this->assertSame( 1, $this->count_payments() );
		$this->assertSame( 503, $this->request( $body )->get_status() );
		$body['payment']['reference'] .= '-new';
		$this->assertSame( 503, $this->request( $body, 'different-request-key' )->get_status() );
		$this->assertSame( 1, $this->count_payments() );
	}

	public function test_authorization_toggles_and_schema_rejections() {
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_RENEWALS, false );
		$this->assertSame( 503, $this->request()->get_status() );
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_RENEWALS, true );
		wp_set_current_user( $this->member );
		$this->assertSame( 403, $this->request()->get_status() );
		wp_set_current_user( $this->actor );
		foreach ( array( $this->body + array( 'expiry' => '2099-01-01' ), array_replace_recursive( $this->body, array( 'payment' => array( 'amount' => 30 ) ) ), array_replace_recursive( $this->body, array( 'payment' => array( 'payment_log_id' => 1 ) ) ) ) as $body ) {
			$this->assertSame( 400, $this->request( $body )->get_status() );
		}
		$body = $this->body + array( 'dry_run' => false );
		$this->assertSame( 400, $this->request( $body )->get_status() );
		$this->assertSame( 400, $this->request( $body, 'bad' )->get_status() );
		$this->assertSame( 0, $this->count_payments() );
	}

	public function test_payment_mismatch_and_state_change() {
		$body = $this->commit_body();
		update_user_meta( $this->member, 'arm_user_suspended_plan_ids', array( 99 ) );
		$this->assertSame( 'bono_arm_api_state_changed', $this->request( $body )->get_data()['code'] );
		$this->body['payment']['amount'] = '29.99';
		$this->assertSame( 'bono_arm_api_payment_mismatch', $this->request()->get_data()['code'] );
		$this->assertSame( 0, $this->count_payments() );
	}
	public function test_both_capabilities_are_required_and_upgrade_grants_once() {
		$role = get_role( 'administrator' );
		$role->remove_cap( Capabilities::RENEW_MEMBERS );
		update_option( BONO_ARM_API_OPTION_CAPS_GRANTED, array_diff( Capabilities::all(), array( Capabilities::RENEW_MEMBERS ) ) );
		Capabilities::maybe_upgrade();
		$this->assertTrue( $role->has_cap( Capabilities::RENEW_MEMBERS ) );
		$role->remove_cap( Capabilities::RENEW_MEMBERS );
		Capabilities::maybe_upgrade();
		$this->assertFalse( $role->has_cap( Capabilities::RENEW_MEMBERS ) );
		wp_set_current_user( 0 ); wp_set_current_user( $this->actor );
		$this->assertSame( 403, $this->request()->get_status() );
		$role->add_cap( Capabilities::RENEW_MEMBERS );
		$role->remove_cap( Capabilities::ACTIVATE_MEMBERS );
		wp_set_current_user( 0 ); wp_set_current_user( $this->actor );
		$this->assertSame( 403, $this->request()->get_status() );
	}

	public function test_native_monthly_and_leap_year_schedules_are_read_only() {
		if ( ! getenv( 'BONO_ARMEMBER_SOURCE' ) ) { $this->markTestSkipped( 'Licensed source needed for schedule parity cases.' ); }
		$gateway = new BonoArmApi\ARMember\Renewal_Gateway( $this->repository );
		$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
		$cases = array(
			array( '2025-10-01', 'Y', 1, 'transaction_day', '2027-10-01' ),
			array( '2026-01-10', 'M', 1, 15, '2026-03-15' ),
			array( '2024-10-01', 'Y', 2, 'transaction_day', '2028-10-01' ),
			array( '2024-02-29', 'Y', 1, 'transaction_day', '2026-03-01' ),
			array( '2026-01-31', 'M', 1, 'transaction_day', '2026-03-31' ),
			array( '2026-03-07', 'D', 1, 'transaction_day', '2026-03-09' ),
		);
		update_option( 'timezone_string', 'America/Los_Angeles' );
		foreach ( $cases as list( $start, $period, $interval, $billing, $expected ) ) {
			$data['arm_start_plan'] = strtotime( $start );
			$data['arm_completed_recurring'] = 2;
			$data['arm_current_plan_detail']['arm_subscription_plan_options']['recurring']['manual_billing_start'] = $billing;
			$data['arm_current_plan_detail']['arm_subscription_plan_options']['payment_cycles'] = array( array( 'billing_type' => $period, 'billing_cycle' => $interval, 'recurring_time' => 'infinite', 'cycle_amount' => '30.00' ) );
			$before = get_user_meta( $this->member );
			$this->assertSame( $expected, gmdate( 'Y-m-d', (int) $gateway->calculate_due( $this->member, 12, $data ) ) );
			$this->assertSame( $before, get_user_meta( $this->member ) );
		}
	}

	public function test_payment_failures_do_not_reuse_historical_or_wrong_payment() {
		global $arm_payment_gateways, $wpdb;
		$id = $arm_payment_gateways->arm_save_payment_log( array( 'arm_user_id' => $this->member, 'arm_plan_id' => 12, 'arm_payment_gateway' => 'manual', 'arm_payment_mode' => 'manual_subscription', 'arm_transaction_status' => 'success', 'arm_amount' => '30.00', 'arm_currency' => 'USD', 'arm_transaction_id' => 'historical' ) );
		$this->body['payment'] = array( 'mode' => 'existing', 'payment_log_id' => (int) $id );
		$this->assertSame( 'bono_arm_api_payment_mismatch', $this->request()->get_data()['code'] );
		$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
		$wpdb->update( $wpdb->prefix . 'arm_payment_log', array( 'arm_extra_vars' => maybe_serialize( array( 'bono_sponsored_renewal' => array( 'start_plan' => $data['arm_start_plan'], 'completed_cycles' => 2, 'sponsor_name' => 'Sponsor' ) ) ) ), array( 'arm_log_id' => $id ) );
		foreach ( array( 'arm_user_id' => $this->actor, 'arm_plan_id' => 99, 'arm_amount' => '29.00', 'arm_currency' => 'EUR', 'arm_transaction_status' => 'pending', 'arm_payment_gateway' => 'stripe' ) as $field => $bad ) {
			$record = $this->repository->payment( $id );
			$wpdb->update( $wpdb->prefix . 'arm_payment_log', array( $field => $bad ), array( 'arm_log_id' => $id ) );
			$this->assertSame( 'bono_arm_api_payment_mismatch', $this->request()->get_data()['code'], $field );
			$wpdb->update( $wpdb->prefix . 'arm_payment_log', array( $field => $record[$field] ), array( 'arm_log_id' => $id ) );
		}
		$this->assertSame( 1, $this->count_payments() );
	}

	public function test_preserves_other_suspensions_and_restores_only_payment_suspension() {
		$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
		$data['arm_grace_period_action'] = 'failed_payment';
		$data['arm_is_user_in_grace'] = 1;
		update_user_meta( $this->member, 'arm_user_plan_12', $data );
		update_user_meta( $this->member, 'arm_user_suspended_plan_ids', array( 12, 99 ) );
		$this->assertSame( 201, $this->request( $this->commit_body() )->get_status() );
		$this->assertSame( array( 99 ), get_user_meta( $this->member, 'arm_user_suspended_plan_ids', true ) );
		$this->assertSame( 0, get_user_meta( $this->member, 'arm_user_plan_12', true )['arm_is_user_in_grace'] );
		$this->assertSame( $data['arm_start_plan'], get_user_meta( $this->member, 'arm_user_plan_12', true )['arm_start_plan'] );
	}

	public function test_unrelated_restrictions_cancelled_and_exhausted_are_rejected() {
		global $wpdb;
		$original = get_user_meta( $this->member, 'arm_user_plan_12', true );
		foreach ( array( 1, 2, 3, 4, 6 ) as $secondary ) {
			$wpdb->update( $wpdb->prefix . 'arm_members', array( 'arm_secondary_status' => $secondary ), array( 'arm_user_id' => $this->member ) );
			$this->assertSame( 422, $this->request()->get_status() );
		}
		$wpdb->update( $wpdb->prefix . 'arm_members', array( 'arm_secondary_status' => 0 ), array( 'arm_user_id' => $this->member ) );
		$data = $original; $data['arm_cencelled_plan'] = 1;
		update_user_meta( $this->member, 'arm_user_plan_12', $data );
		$this->assertSame( 422, $this->request()->get_status() );
		$data = $original; $data['arm_current_plan_detail']['arm_subscription_plan_options']['recurring']['time'] = 1;
		update_user_meta( $this->member, 'arm_user_plan_12', $data );
		$this->assertSame( 422, $this->request()->get_status() );
		$this->assertSame( 0, $this->count_payments() );
	}

	public function test_already_active_is_no_op_and_mail_filter_is_scoped() {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'arm_members', array( 'arm_primary_status' => 1 ), array( 'arm_user_id' => $this->member ) );
		$body = $this->commit_body();
		$probe = function () {
			$this->assertFalse( ( new ARM_manage_communication() )->test_delivery_filter( null ) );
			$this->assertNull( apply_filters( 'pre_wp_mail', null, array() ) );
		};
		add_action( 'arm_before_add_transaction', $probe );
		$this->assertSame( 201, $this->request( $body )->get_status() );
		remove_action( 'arm_before_add_transaction', $probe );
		$this->assertNull( ( new ARM_manage_communication() )->test_delivery_filter( null ) );
		$this->assertSame( 1, $this->count_payments() );
	}

	public function test_lock_conflict_from_another_connection_is_read_only() {
		global $wpdb;
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name = 'bono:' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':member:' . $this->member ), 0, 58 );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
		try { $this->assertSame( 'bono_arm_api_renewal_in_progress', $this->request()->get_data()['code'] ); }
		finally { $other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); $other->close(); }
		$this->assertSame( 0, $this->count_payments() );
	}

	public function test_stale_token_actor_binding_and_status_access() {
		$body = $this->commit_body();
		$body['expected_state'] = '1000000000.' . str_repeat( 'a', 64 );
		$this->assertSame( 'bono_arm_api_state_changed', $this->request( $body )->get_data()['code'] );
		$body = $this->commit_body();
		$actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $actor );
		$this->assertSame( 'bono_arm_api_state_changed', $this->request( $body )->get_data()['code'] );
		wp_set_current_user( $this->actor );
		$result = $this->request( $body )->get_data();
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_RENEWALS, false );
		$route = '/bono_armember/v2/renewals/' . $result['operation_id'];
		$this->assertSame( 200, rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status() );
		wp_set_current_user( $this->member );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status() );
	}

	public function test_existing_payment_is_unchanged_after_activation_failure() {
		global $arm_payment_gateways;
		$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
		$id = $arm_payment_gateways->arm_save_payment_log( array( 'arm_user_id' => $this->member, 'arm_plan_id' => 12, 'arm_payment_gateway' => 'manual', 'arm_payment_mode' => 'manual_subscription', 'arm_transaction_status' => 'success', 'arm_amount' => '30.00', 'arm_currency' => 'USD', 'arm_transaction_id' => 'external-allocation', 'arm_extra_vars' => maybe_serialize( array( 'note' => wp_json_encode( array( 'sponsor_name' => 'Sponsor', 'start_plan' => $data['arm_start_plan'], 'completed_cycles' => 2 ) ) ) ) ) );
		$this->body['payment'] = array( 'mode' => 'existing', 'payment_log_id' => (int) $id );
		$record = $this->repository->payment( $id );
		$body = $this->commit_body();
		$fail = function ( $value, $user_id, $key ) {
			if ( $this->member === $user_id && 'arm_primary_status' === $key ) { throw new RuntimeException( 'Activation failure' ); }
			return $value;
		};
		add_filter( 'update_user_metadata', $fail, 10, 3 );
		$response = $this->request( $body );
		remove_filter( 'update_user_metadata', $fail, 10 );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( $record, $this->repository->payment( $id ) );
		$this->assertSame( 1, $this->count_payments() );
		$this->assertSame( 503, $this->request( $body )->get_status() );
		$this->assertSame( $record, $this->repository->payment( $id ) );
	}

	/** @dataProvider write_failure_stages */
	public function test_failure_at_cycle_or_payment_intent_blocks_reexecution( $stage ) {
		$body = $this->commit_body();
		$fail = function ( $value = null, $user_id = null, $key = null ) use ( $stage ) {
			if ( 'payment' === $stage || ( $this->member === $user_id && 'arm_user_plan_12' === $key ) ) { throw new RuntimeException( 'Injected failure' ); }
			return $value;
		};
		$hook = 'cycle' === $stage ? 'update_user_metadata' : 'arm_before_add_transaction';
		add_filter( $hook, $fail, 10, 3 );
		$response = $this->request( $body );
		remove_filter( $hook, $fail, 10 );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 0, $this->count_payments() );
		$this->assertSame( 503, $this->request( $body )->get_status() );
		$this->assertSame( 0, $this->count_payments() );
	}
	public function write_failure_stages() { return array( array( 'cycle' ), array( 'payment' ) ); }

	public function test_selected_cycle_tax_and_currency() {
		global $arm_global_settings;
		$arm_global_settings->global_settings += array( 'enable_tax' => 1, 'tax_type' => 'common_tax', 'tax_amount' => '10', 'arm_tax_include_exclude_flag' => 0 );
		$this->assertSame( 'bono_arm_api_payment_mismatch', $this->request()->get_data()['code'] );
		$this->body['payment']['amount'] = '33.00';
		$this->assertSame( 200, $this->request()->get_status() );
		$this->body['payment']['currency'] = 'EUR';
		$this->assertSame( 'bono_arm_api_payment_mismatch', $this->request()->get_data()['code'] );
		$this->body['payment']['currency'] = 'USD';
		if ( getenv( 'BONO_ARMEMBER_SOURCE' ) ) {
			$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
			$data['arm_payment_cycle'] = 1;
			$data['arm_current_plan_detail']['arm_subscription_plan_options']['payment_cycles'] = array(
				array( 'billing_type' => 'Y', 'billing_cycle' => 1, 'cycle_amount' => '30.00', 'recurring_time' => 'infinite' ),
				array( 'billing_type' => 'Y', 'billing_cycle' => 1, 'cycle_amount' => '40.00', 'recurring_time' => 'infinite' ),
			);
			update_user_meta( $this->member, 'arm_user_plan_12', $data );
			$this->assertSame( 'bono_arm_api_payment_mismatch', $this->request()->get_data()['code'] );
			$this->body['payment']['amount'] = '44.00';
			$this->assertSame( 201, $this->request( $this->commit_body() )->get_status() );
		}
	}

	public function test_dependency_unavailable_and_options_schema() {
		global $arm_members_class;
		$arm_members_class = null;
		$this->assertSame( 'bono_arm_api_armember_unavailable', $this->request()->get_data()['code'] );
		$this->assertSame( 0, $this->count_payments() );
		$request = new WP_REST_Request( 'OPTIONS', '/bono_armember/v2/members/' . $this->member . '/renewals' );
		$schema = rest_get_server()->dispatch( $request )->get_data()['schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertArrayHasKey( 'payment', $schema['properties'] );
	}

	public function test_received_payment_time_and_sponsor_are_recorded_accurately() {
		update_option( 'timezone_string', 'America/Los_Angeles' );
		$this->body['payment']['paid_at'] = gmdate( 'Y' ) . '-11-13T09:00:00-08:00';
		$result = $this->request( $this->commit_body() )->get_data();
		$this->assertSame( 'completed', $result['state'] );
		$record = $this->repository->payment( $result['payment']['payment_log_id'] );
		$this->assertSame( gmdate( 'Y' ) . '-11-13 09:00:00', $record['arm_payment_date'] );
		$this->assertSame( 'manual', $record['arm_payment_gateway'] );
		$this->assertSame( 'success', $record['arm_transaction_status'] );
		$extra = arm_maybe_unserialize( $record['arm_extra_vars'] );
		$this->assertSame( "Sponsor O'Neil", $extra['bono_sponsored_renewal']['sponsor_name'] );
		$this->assertSame( $this->actor, $extra['bono_sponsored_renewal']['actor_id'] );
		$this->assertSame( $result['operation_id'], $extra['bono_sponsored_renewal']['operation_id'] );
		$this->assertSame( 2, $extra['bono_sponsored_renewal']['completed_cycles'] );
	}

	public function test_existing_sponsor_payment_does_not_hide_nonpayment_suspension() {
		global $arm_payment_gateways;
		$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
		$base = array( 'arm_user_id' => $this->member, 'arm_plan_id' => 12, 'arm_payment_gateway' => 'manual', 'arm_payment_mode' => 'manual_subscription', 'arm_amount' => '30.00', 'arm_currency' => 'USD' );
		$arm_payment_gateways->arm_save_payment_log( $base + array( 'arm_transaction_status' => 'failed', 'arm_transaction_id' => 'failed-cycle' ) );
		$id = $arm_payment_gateways->arm_save_payment_log( $base + array( 'arm_transaction_status' => 'success', 'arm_transaction_id' => 'sponsor-allocation', 'arm_extra_vars' => maybe_serialize( array( 'note' => wp_json_encode( array( 'sponsor_name' => 'Sponsor', 'start_plan' => $data['arm_start_plan'], 'completed_cycles' => 2 ) ) ) ) ) );
		update_user_meta( $this->member, 'arm_user_suspended_plan_ids', array( 12 ) );
		$this->body['payment'] = array( 'mode' => 'existing', 'payment_log_id' => (int) $id );
		$result = $this->request( $this->commit_body() );
		$this->assertSame( 201, $result->get_status(), wp_json_encode( $result->get_data() ) );
		$this->assertFalse( $result->get_data()['payment']['created'] );
		$this->assertSame( 2, $this->count_payments() );
		$this->assertSame( array(), get_user_meta( $this->member, 'arm_user_suspended_plan_ids', true ) );
	}

	public function test_confirmed_nonpayment_restores_cleared_grace_after_later_success() {
		global $arm_payment_gateways, $wpdb;
		$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
		$year = (int) gmdate( 'Y' ) - 1;
		$base = array( 'arm_user_id' => $this->member, 'arm_plan_id' => 12, 'arm_payment_gateway' => 'manual', 'arm_payment_mode' => 'manual_subscription', 'arm_amount' => '30.00', 'arm_currency' => 'USD' );
		$arm_payment_gateways->arm_save_payment_log( $base + array( 'arm_transaction_status' => 'failed', 'arm_payment_date' => $year . '-06-01 00:00:00' ) );
		$arm_payment_gateways->arm_save_payment_log( $base + array( 'arm_transaction_status' => 'success', 'arm_payment_date' => $year . '-08-01 00:00:00' ) );
		$wpdb->update( $wpdb->prefix . 'arm_members', array( 'arm_primary_status' => 1 ), array( 'arm_user_id' => $this->member ) );
		$data['arm_grace_period_action'] = '';
		$data['arm_is_user_in_grace'] = 0;
		update_user_meta( $this->member, 'arm_user_plan_12', $data );
		update_user_meta( $this->member, 'arm_user_suspended_plan_ids', array( 12, 99 ) );
		$before = get_user_meta( $this->member );
		$rejected = $this->request();
		$this->assertSame( 422, $rejected->get_status() );
		$this->assertSame( 'suspension_reason_unverified', $rejected->get_data()['data']['reason'] );
		$this->assertSame( $before, get_user_meta( $this->member ) );
		$this->body['suspension_reason'] = 'nonpayment';
		$body = $this->commit_body();
		$this->assertSame( $before, get_user_meta( $this->member ) );
		$this->assertSame( 2, $this->count_payments() );
		$response = $this->request( $body );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 3, $this->count_payments() );
		$this->assertSame( array( 99 ), get_user_meta( $this->member, 'arm_user_suspended_plan_ids', true ) );
		$this->assertSame( 2, $response->get_data()['after']['completed_cycles'] );
		$operation = $this->repository->get( $response->get_data()['operation_id'] );
		$this->assertSame( 'nonpayment', $operation['payload']['command']['suspension_reason'] );
		$this->assertSame( 200, $this->request( $body )->get_status() );
		unset( $body['suspension_reason'] );
		$this->assertSame( 'bono_arm_api_idempotency_conflict', $this->request( $body )->get_data()['code'] );
	}

	/** @dataProvider unrelated_restrictions */
	public function test_nonpayment_confirmation_cannot_override_other_restrictions( $restriction, $expected ) {
		global $wpdb;
		$this->body['suspension_reason'] = 'nonpayment';
		$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
		update_user_meta( $this->member, 'arm_user_suspended_plan_ids', array( 12 ) );
		switch ( $restriction ) {
			case 'cancelled': $data['arm_cencelled_plan'] = 1; break;
			case 'auto': $data['arm_payment_mode'] = 'auto_debit_subscription'; break;
			case 'grace': $data['arm_grace_period_action'] = 'eot'; break;
			case 'admin': $wpdb->update( $wpdb->prefix . 'arm_members', array( 'arm_secondary_status' => 2 ), array( 'arm_user_id' => $this->member ) ); break;
			case 'other':
				$wpdb->update( $wpdb->prefix . 'arm_members', array( 'arm_secondary_status' => 5 ), array( 'arm_user_id' => $this->member ) );
				update_user_meta( $this->member, 'arm_user_suspended_plan_ids', array( 12, 99 ) ); break;
			case 'future': $data['arm_next_due_payment'] = strtotime( '+1 year' ); break;
			case 'exhausted': $data['arm_current_plan_detail']['arm_subscription_plan_options']['recurring']['time'] = 1; break;
		}
		update_user_meta( $this->member, 'arm_user_plan_12', $data );
		$before = get_user_meta( $this->member );
		$response = $this->request();
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( $expected, $response->get_data()['data']['reason'] );
		$this->assertSame( $before, get_user_meta( $this->member ) );
		$this->assertSame( 0, $this->count_payments() );
	}
	public function unrelated_restrictions() { return array(
		array( 'cancelled', 'subscription_cancelled' ), array( 'auto', 'payment_mode_not_manual' ),
		array( 'grace', 'unrelated_grace_action' ), array( 'admin', 'account_restricted' ),
		array( 'other', 'other_plan_restriction' ), array( 'future', 'confirmed_suspension_not_overdue' ),
		array( 'exhausted', 'recurrence_exhausted' ),
	); }

	public function test_nonpayment_confirmation_schema_and_schedule_reason() {
		$this->body['suspension_reason'] = 'administrative';
		$this->assertSame( 400, $this->request()->get_status() );
		$this->body['suspension_reason'] = true;
		$this->assertSame( 400, $this->request()->get_status() );
		$this->body['suspension_reason'] = 'nonpayment';
		$data = get_user_meta( $this->member, 'arm_user_plan_12', true );
		unset( $data['arm_next_due_payment'] );
		update_user_meta( $this->member, 'arm_user_plan_12', $data );
		$response = $this->request()->get_data();
		$this->assertSame( 'invalid_schedule_field', $response['data']['reason'] );
		$this->assertSame( 'arm_next_due_payment', $response['data']['field'] );
	}

}
