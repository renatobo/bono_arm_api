<?php
use BonoArmApi\Capabilities;

final class ArmemberFixtureTest extends WP_UnitTestCase {
	private $administrator_id;
	private $member_id;

	public function set_up() {
		parent::set_up();
		global $wpdb, $arm_members_class;

		// The test suite rewrites these into temporary tables, which SHOW TABLES cannot see,
		// so the availability probe is primed directly.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}arm_payment_log (
				arm_log_id INT(11) NOT NULL AUTO_INCREMENT,
				arm_invoice_id INT(11) NOT NULL DEFAULT '0',
				arm_user_id BIGINT(20) NOT NULL DEFAULT '0',
				arm_plan_id BIGINT(20) NOT NULL DEFAULT '0',
				arm_payment_gateway VARCHAR(50) NOT NULL DEFAULT '',
				arm_payer_email VARCHAR(255) DEFAULT NULL,
				arm_transaction_status TEXT,
				arm_payment_date DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				arm_amount DOUBLE NOT NULL DEFAULT '0',
				arm_currency VARCHAR(50) DEFAULT NULL,
				arm_extra_vars LONGTEXT,
				PRIMARY KEY (arm_log_id)
			)"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}arm_members (
				arm_member_id BIGINT(20) NOT NULL AUTO_INCREMENT,
				arm_user_id BIGINT(20) NOT NULL DEFAULT '0',
				arm_user_login VARCHAR(60) NOT NULL DEFAULT '',
				arm_primary_status INT(1) NOT NULL DEFAULT '1',
				arm_secondary_status INT(1) NOT NULL DEFAULT '0',
				PRIMARY KEY (arm_member_id)
			)"
		);
		$this->set_tables_available( true );

		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member_id        = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'fixture_member',
			)
		);
		$this->add_member_row( $this->member_id, 'fixture_member', 3 );

		$arm_members_class = new Bono_Arm_Api_Test_Members_Manager();

		Capabilities::activate();
		wp_set_current_user( $this->administrator_id );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		global $arm_members_class;
		$arm_members_class = null;
		parent::tear_down();
	}

	private function set_tables_available( $exists ) {
		set_transient( 'bono_arm_api_tables_' . get_current_blog_id(), array( 'exists' => $exists ), HOUR_IN_SECONDS );
	}

	private function add_member_row( $user_id, $login, $primary_status = 1 ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'arm_members',
			array(
				'arm_user_id'        => $user_id,
				'arm_user_login'     => $login,
				'arm_primary_status' => $primary_status,
			)
		);
	}

	private function add_payment( $invoice_id, $date, $extra_vars = null, $status = 'success' ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'arm_payment_log',
			array(
				'arm_invoice_id'         => $invoice_id,
				'arm_user_id'            => $this->member_id,
				'arm_plan_id'            => 1,
				'arm_payment_gateway'    => 'manual',
				'arm_payer_email'        => 'payer@example.com',
				'arm_transaction_status' => $status,
				'arm_payment_date'       => $date,
				'arm_amount'             => 10,
				'arm_currency'           => 'USD',
				'arm_extra_vars'         => $extra_vars,
			)
		);
	}

	private function get_payments( array $params ) {
		$request = new WP_REST_Request( 'GET', '/bono_armember/v2/payments' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function delete_member( $user_id, $reassign_user_id ) {
		$request = new WP_REST_Request( 'DELETE', '/bono_armember/v2/members/' . $user_id );
		$request->set_param( 'reassign_user_id', $reassign_user_id );
		return rest_get_server()->dispatch( $request );
	}

	/*
	 * Feature toggles.
	 */

	public function test_disabled_v2_payments_return_service_unavailable() {
		delete_option( BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS );
		$this->assertSame( 503, $this->get_payments( array() )->get_status() );
	}

	public function test_disabled_v1_payments_keep_legacy_envelope() {
		delete_option( BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS );
		$request = new WP_REST_Request( 'GET', '/bono_armember/v1/arm_payments_log' );
		$request->set_param( 'arm_invoice_id_gt', 1 );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['status'] );
	}

	public function test_disabled_member_routes_return_service_unavailable() {
		delete_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION );
		delete_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE );

		$activate = new WP_REST_Request( 'POST', '/bono_armember/v2/members/' . $this->member_id . '/activate' );
		$this->assertSame( 503, rest_get_server()->dispatch( $activate )->get_status() );
		$this->assertSame( 503, $this->delete_member( $this->member_id, $this->administrator_id )->get_status() );
		$this->assertNotFalse( get_user_by( 'ID', $this->member_id ) );
	}

	public function test_missing_armember_tables_return_service_unavailable() {
		update_option( BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS, true );
		$this->set_tables_available( false );
		$this->assertSame( 503, $this->get_payments( array() )->get_status() );
	}

	/*
	 * Authorization.
	 */

	public function test_edit_context_requires_payer_details_capability() {
		update_option( BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS, true );
		$this->add_payment( 5, '2026-01-01 10:00:00' );

		$reader_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'ID', $reader_id )->add_cap( Capabilities::READ_PAYMENTS );
		wp_set_current_user( $reader_id );

		$view = $this->get_payments( array() );
		$this->assertSame( 200, $view->get_status() );
		$this->assertArrayNotHasKey( 'payer_email', $view->get_data()['payments'][0] );

		$edit = $this->get_payments( array( 'context' => 'edit' ) );
		$this->assertSame( 403, $edit->get_status() );
		$this->assertSame( 'rest_forbidden_context', $edit->get_data()['code'] );

		get_user_by( 'ID', $reader_id )->add_cap( Capabilities::READ_PAYER_DETAILS );
		// Switching away first forces a fresh WP_User; the same ID returns the cached one.
		wp_set_current_user( 0 );
		wp_set_current_user( $reader_id );
		$granted = $this->get_payments( array( 'context' => 'edit' ) );
		$this->assertSame( 200, $granted->get_status() );
		$this->assertSame( 'payer@example.com', $granted->get_data()['payments'][0]['payer_email'] );
	}

	public function test_upgrade_grants_only_new_capabilities_to_legacy_installs() {
		$administrator = get_role( 'administrator' );
		$administrator->remove_cap( Capabilities::DELETE_MEMBERS );
		$administrator->remove_cap( Capabilities::READ_PAYER_DETAILS );
		update_option( BONO_ARM_API_OPTION_SCHEMA_VERSION, '2.0.0' );
		delete_option( BONO_ARM_API_OPTION_CAPS_GRANTED );

		Capabilities::maybe_upgrade();

		$administrator = get_role( 'administrator' );
		$this->assertTrue( $administrator->has_cap( Capabilities::READ_PAYER_DETAILS ) );
		$this->assertFalse( $administrator->has_cap( Capabilities::DELETE_MEMBERS ) );
		$this->assertSame( BONO_ARM_API_VERSION, get_option( BONO_ARM_API_OPTION_SCHEMA_VERSION ) );
	}

	public function test_upgrade_does_not_restore_a_removed_capability() {
		get_role( 'administrator' )->remove_cap( Capabilities::READ_PAYMENTS );
		update_option( BONO_ARM_API_OPTION_SCHEMA_VERSION, '0.0.1' );

		Capabilities::maybe_upgrade();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::READ_PAYMENTS ) );
	}

	public function test_fresh_install_grants_every_capability() {
		Capabilities::deactivate();
		Capabilities::maybe_upgrade();

		foreach ( Capabilities::all() as $capability ) {
			$this->assertTrue( get_role( 'administrator' )->has_cap( $capability ), $capability );
		}
	}

	/*
	 * Payment reads.
	 */

	public function test_payment_dates_are_converted_from_site_time_to_utc() {
		update_option( BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS, true );
		update_option( 'timezone_string', 'America/Los_Angeles' );
		$this->add_payment( 5, '2026-07-01 10:00:00' );
		$this->add_payment( 6, '1970-01-01 00:00:00' );

		$payments = $this->get_payments( array() )->get_data()['payments'];

		$this->assertSame( '2026-07-01T17:00:00+00:00', $payments[0]['payment_date'] );
		$this->assertSame( '', $payments[1]['payment_date'] );
	}

	public function test_notes_are_parsed_from_serialized_extra_vars() {
		update_option( BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS, true );
		$this->add_payment( 1, '2026-01-01 10:00:00', serialize( array( 'note' => 'Cash at desk' ) ) );
		$this->add_payment( 2, '2026-01-01 10:00:00', serialize( array( 'manual_by' => 'Pagato dall&#039;amministratore' ) ) );
		$this->add_payment( 3, '2026-01-01 10:00:00', serialize( array( 'manual_by' => 'Paid By system' ) ) );
		$this->add_payment( 4, '2026-01-01 10:00:00', 'not serialized' );
		$this->add_payment( 5, '2026-01-01 10:00:00', 'O:8:"stdClass":1:{s:4:"note";s:1:"x";}' );

		$notes = wp_list_pluck( $this->get_payments( array( 'context' => 'edit' ) )->get_data()['payments'], 'notes' );

		$this->assertSame( array( 'Cash at desk', "Pagato dall'amministratore", 'Paid By system', '', '' ), $notes );
	}

	public function test_cursor_pagination_and_totals() {
		update_option( BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS, true );
		$this->add_payment( 10, '2026-01-01 10:00:00' );
		$this->add_payment( 11, '2026-01-01 10:00:00', null, 'failed' );
		$this->add_payment( 12, '2026-01-01 10:00:00' );
		$this->add_payment( 13, '2026-01-01 10:00:00' );

		$first = $this->get_payments(
			array(
				'per_page'       => 2,
				'include_totals' => true,
			)
		);
		$data  = $first->get_data();
		$this->assertSame( array( 10, 12 ), wp_list_pluck( $data['payments'], 'invoice_id' ) );
		$this->assertTrue( $data['pagination']['has_more'] );
		$this->assertSame( 12, $data['pagination']['next_cursor'] );
		$this->assertSame( 3, $first->get_headers()['X-WP-Total'] );

		$second = $this->get_payments(
			array(
				'per_page'         => 2,
				'after_invoice_id' => 12,
			)
		)->get_data();
		$this->assertSame( array( 13 ), wp_list_pluck( $second['payments'], 'invoice_id' ) );
		$this->assertFalse( $second['pagination']['has_more'] );
	}

	public function test_v1_accepts_zero_cursor() {
		// v1 counts with a self-join, and MySQL cannot open a temporary table twice in one
		// query, so the fixture tables cannot serve v1 reads. The argument contract is tested
		// directly; the shared WHERE clause is exercised through v2 above.
		$route    = rest_get_server()->get_routes()['/bono_armember/v1/arm_payments_log'][0];
		$validate = $route['args']['arm_invoice_id_gt']['validate_callback'];

		$this->assertTrue( $route['args']['arm_invoice_id_gt']['required'] );
		$this->assertTrue( $validate( '0' ) );
		$this->assertTrue( $validate( 1450 ) );
		$this->assertFalse( $validate( '-1' ) );
		$this->assertFalse( $validate( '1.5' ) );
	}

	public function test_missing_invoice_index_is_detected() {
		global $wpdb;
		// ALTER TABLE would implicitly commit the test transaction, so only the unindexed
		// fixture is probed here.
		$repository = new BonoArmApi\Infrastructure\Payment_Repository();
		$repository->flush_schema_cache();
		$this->set_tables_available( true );

		$this->assertFalse( $repository->invoice_index_exists() );
		$this->assertStringContainsString( "`{$wpdb->prefix}arm_payment_log` ADD INDEX `bono_invoice_id` (`arm_invoice_id`)", $repository->invoice_index_sql() );
	}

	/*
	 * Member mutations.
	 */

	public function test_activation_updates_member_and_fires_action() {
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION, true );
		$fired = did_action( 'bono_arm_api_member_activated' );

		$request  = new WP_REST_Request( 'POST', '/bono_armember/v2/members/' . $this->member_id . '/activate' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['primary_status'] );
		$this->assertSame( $fired + 1, did_action( 'bono_arm_api_member_activated' ) );
	}

	public function test_activation_rejects_users_without_member_row() {
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION, true );
		$outsider_id = self::factory()->user->create();

		$request  = new WP_REST_Request( 'POST', '/bono_armember/v2/members/' . $outsider_id . '/activate' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'bono_arm_api_member_not_found', $response->get_data()['code'] );
		$this->assertSame( '', get_user_meta( $outsider_id, 'arm_primary_status', true ) );
	}

	public function test_delete_runs_manual_cleanup_and_fires_action() {
		global $arm_members_class;
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE, true );
		$fired = did_action( 'bono_arm_api_member_deleted' );

		$response = $this->delete_member( $this->member_id, $this->administrator_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'manual_fallback', $response->get_data()['cleanup_mode'] );
		$this->assertSame( array( $this->member_id ), $arm_members_class->before );
		$this->assertSame( array( $this->member_id ), $arm_members_class->after );
		$this->assertFalse( get_user_by( 'ID', $this->member_id ) );
		$this->assertSame( $fired + 1, did_action( 'bono_arm_api_member_deleted' ) );
	}

	public function test_delete_refuses_administrators_before_any_cleanup() {
		global $arm_members_class;
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE, true );
		$other_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$response = $this->delete_member( $other_admin_id, $this->administrator_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'bono_arm_api_delete_protected', $response->get_data()['code'] );
		$this->assertSame( array(), $arm_members_class->before );
		$this->assertNotFalse( get_user_by( 'ID', $other_admin_id ) );
	}

	public function test_delete_filter_can_allow_administrators() {
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE, true );
		$other_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_filter( 'bono_arm_api_can_delete_member', '__return_true' );

		$response = $this->delete_member( $other_admin_id, $this->administrator_id );

		remove_filter( 'bono_arm_api_can_delete_member', '__return_true' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( get_user_by( 'ID', $other_admin_id ) );
	}

	public function test_v1_delete_refusal_keeps_legacy_envelope() {
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE, true );
		$other_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$request  = new WP_REST_Request( 'POST', '/bono_armember/v1/members/' . $other_admin_id . '/delete' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['status'] );
		$this->assertNotFalse( get_user_by( 'ID', $other_admin_id ) );
	}
}
