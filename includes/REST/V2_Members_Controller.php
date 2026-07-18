<?php
namespace BonoArmApi\REST;

use BonoArmApi\ARMember\Gateway;
use BonoArmApi\Capabilities;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class V2_Members_Controller extends WP_REST_Controller {
	private $gateway;

	public function __construct( Gateway $gateway ) {
		$this->namespace = BONO_ARM_API_V2_NAMESPACE;
		$this->rest_base = 'members';
		$this->gateway   = $gateway;
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<user_id>[\d]+)/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'activate' ),
				'permission_callback' => array( $this, 'can_activate' ),
				'args'                => array(
					'user_id'    => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'send_email' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<user_id>[\d]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'args'                => array(
					'user_id'          => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'reassign_user_id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
	}

	public function can_activate() {
		return current_user_can( Capabilities::ACTIVATE_MEMBERS ) ? true : $this->forbidden();
	}

	public function can_delete( WP_REST_Request $request ) {
		if ( ! current_user_can( Capabilities::DELETE_MEMBERS ) || ! current_user_can( 'delete_user', (int) $request['user_id'] ) ) {
			return $this->forbidden();
		}

		if ( get_current_user_id() === (int) $request['user_id'] ) {
			return new WP_Error( 'bono_arm_api_self_delete', __( 'You cannot delete the account used to authenticate this request.', 'bono-arm-api' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public function activate( WP_REST_Request $request ) {
		if ( ! get_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION, false ) ) {
			return new WP_Error( 'bono_arm_api_feature_disabled', __( 'Member activation is disabled.', 'bono-arm-api' ), array( 'status' => 503 ) );
		}

		$result = $this->gateway->activate_member( (int) $request['user_id'], (bool) $request['send_email'] );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function delete( WP_REST_Request $request ) {
		if ( ! get_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE, false ) ) {
			return new WP_Error( 'bono_arm_api_feature_disabled', __( 'Member deletion is disabled.', 'bono-arm-api' ), array( 'status' => 503 ) );
		}

		$result = $this->gateway->delete_member( (int) $request['user_id'], (int) $request['reassign_user_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		unset( $result['user_email'] );
		return new WP_REST_Response( $result, 200 );
	}

	private function forbidden() {
		return new WP_Error( 'rest_forbidden', __( 'You are not allowed to perform this action.', 'bono-arm-api' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
