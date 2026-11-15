<?php
namespace BonoArmApi\REST;

use BonoArmApi\Capabilities;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Existing ARMember badge definitions and per-user assignments. */
final class V2_Badges_Controller extends WP_REST_Controller {
	public function __construct() {
		$this->namespace = BONO_ARM_API_V2_NAMESPACE;
	}

	public function register_routes() {
		$id = array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
		register_rest_route(
			$this->namespace,
			'/badges',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/members/(?P<user_id>[\d]+)/badges',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array( 'user_id' => $id ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/members/(?P<user_id>[\d]+)/badges/(?P<badge_id>[\d]+)',
			array(
				'methods'             => 'PUT, DELETE',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'user_id'  => $id,
					'badge_id' => $id,
				),
			)
		);
	}

	public function permission() {
		return current_user_can( Capabilities::MANAGE_BADGES ) ? true : new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to perform this action.', 'bono-arm-api' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function response_headers( $response, $server, $request ) {
		if ( preg_match( '#^/' . preg_quote( $this->namespace, '#' ) . '/(?:badges|members/\d+/badges(?:/\d+)?)$#D', $request->get_route() ) ) {
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	public function handle( WP_REST_Request $request ) {
		if ( ! get_option( BONO_ARM_API_OPTION_ENABLE_BADGES, false ) ) {
			return new WP_Error( 'bono_arm_api_feature_disabled', __( 'Badge endpoints are disabled.', 'bono-arm-api' ), array( 'status' => 503 ) );
		}
		$result = ( new \BonoArmApi\ARMember\Badge_Gateway() )->handle( $request->get_method(), (int) $request['user_id'], (int) $request['badge_id'] );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}
}
