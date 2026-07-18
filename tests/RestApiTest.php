<?php
final class RestApiTest extends WP_UnitTestCase {
	private $administrator_id;

	public function set_up() {
		parent::set_up();
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		BonoArmApi\Capabilities::activate();
		do_action( 'rest_api_init' );
	}

	public function test_v1_route_names_remain_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/bono_armember/v1/arm_payments_log', $routes );
		$this->assertArrayHasKey( '/bono_armember/v1/members/(?P<user_id>[\d]+)/activate', $routes );
		$this->assertArrayHasKey( '/bono_armember/v1/members/(?P<user_id>[\d]+)/delete', $routes );
	}

	public function test_v2_routes_are_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/bono_armember/v2/payments', $routes );
		$this->assertArrayHasKey( '/bono_armember/v2/members/(?P<user_id>[\d]+)/activate', $routes );
		$this->assertArrayHasKey( '/bono_armember/v2/members/(?P<user_id>[\d]+)', $routes );
	}

	public function test_anonymous_payment_request_is_forbidden() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/bono_armember/v2/payments' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_self_delete_is_rejected_before_deletion() {
		wp_set_current_user( $this->administrator_id );
		update_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE, true );
		$request = new WP_REST_Request( 'DELETE', '/bono_armember/v2/members/' . $this->administrator_id );
		$request->set_param( 'user_id', $this->administrator_id );
		$request->set_param( 'reassign_user_id', self::factory()->user->create() );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertNotFalse( get_user_by( 'ID', $this->administrator_id ) );
	}

	public function test_v2_requires_reassignment_target() {
		wp_set_current_user( $this->administrator_id );
		$request  = new WP_REST_Request( 'DELETE', '/bono_armember/v2/members/123' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
	}
}
