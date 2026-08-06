<?php
namespace BonoArmApi\REST;

use BonoArmApi\ARMember\Gateway;
use BonoArmApi\Infrastructure\Payment_Repository;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class V1_Controller extends WP_REST_Controller {
	private $repository;
	private $gateway;

	public function __construct( Payment_Repository $repository, Gateway $gateway ) {
		$this->namespace  = BONO_ARM_API_NAMESPACE;
		$this->repository = $repository;
		$this->gateway    = $gateway;
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/arm_payments_log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'payments' ),
				'permission_callback' => static function () {
					return current_user_can( BONO_ARM_API_CAP_READ_PAYMENTS );
				},
				'args'                => $this->payment_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/members/(?P<user_id>[\d]+)/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'activate' ),
				'permission_callback' => static function () {
					return current_user_can( BONO_ARM_API_CAP_ACTIVATE_MEMBERS );
				},
				'args'                => $this->member_args( true ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/members/(?P<user_id>[\d]+)/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'args'                => $this->member_args( false ),
			)
		);
	}

	public function payments( WP_REST_Request $request ) {
		if ( ! get_option( BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS, false ) ) {
			return $this->legacy_response( 0, __( 'API route not enabled, check your settings', 'bono-arm-api' ), array(), 403 );
		}

		$result = $this->repository->get_page(
			(int) $request['arm_invoice_id_gt'],
			(int) $request['arm_plan_id'],
			(int) $request['arm_page'],
			(int) $request['arm_perpage']
		);

		if ( is_wp_error( $result ) ) {
			return $this->legacy_error( $result );
		}

		return $this->legacy_response(
			1,
			__( 'Successfully response result.', 'bono-arm-api' ),
			array(
				'payments'   => $result['payments'],
				'pagination' => array(
					'page'        => (int) $request['arm_page'],
					'per_page'    => (int) $request['arm_perpage'],
					'total_count' => $result['total_count'],
					'total_pages' => (int) ceil( $result['total_count'] / (int) $request['arm_perpage'] ),
				),
			)
		);
	}

	public function activate( WP_REST_Request $request ) {
		if ( ! get_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION, false ) ) {
			return $this->legacy_response( 0, __( 'API route not enabled, check your settings', 'bono-arm-api' ), array(), 403 );
		}

		$result = $this->gateway->activate_member( (int) $request['user_id'], (bool) $request['send_email'] );
		return is_wp_error( $result ) ? $this->legacy_error( $result ) : $this->legacy_response( 1, __( 'Member activated successfully.', 'bono-arm-api' ), $result );
	}

	public function delete( WP_REST_Request $request ) {
		if ( ! get_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE, false ) ) {
			return $this->legacy_response( 0, __( 'API route not enabled, check your settings', 'bono-arm-api' ), array(), 403 );
		}

		$result = $this->gateway->delete_member( (int) $request['user_id'], get_current_user_id() );
		return is_wp_error( $result ) ? $this->legacy_error( $result ) : $this->legacy_response( 1, __( 'Member deleted successfully.', 'bono-arm-api' ), $result );
	}

	public function can_delete( WP_REST_Request $request ) {
		$user_id = absint( $request['user_id'] );

		if ( ! $user_id || ! current_user_can( BONO_ARM_API_CAP_DELETE_MEMBERS ) || ! current_user_can( 'delete_user', $user_id ) ) {
			return false;
		}

		if ( get_current_user_id() === $user_id ) {
			return new WP_Error( 'bono_arm_api_self_delete', __( 'You cannot delete the account used to authenticate this request.', 'bono-arm-api' ), array( 'status' => 403 ) );
		}

		return true;
	}

	private function payment_args() {
		return array(
			'arm_plan_id'       => $this->positive_integer_arg( false ),
			'arm_invoice_id_gt' => $this->positive_integer_arg( true ),
			'arm_page'          => $this->bounded_integer_arg( 1, BONO_ARM_API_MAX_PAGE, 1 ),
			'arm_perpage'       => $this->bounded_integer_arg( 1, BONO_ARM_API_MAX_PER_PAGE, 50 ),
		);
	}

	private function member_args( $include_email ) {
		$args = array( 'user_id' => $this->positive_integer_arg( true ) );
		if ( $include_email ) {
			$args['send_email'] = array(
				'type'              => 'boolean',
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			);
		}
		return $args;
	}

	private function positive_integer_arg( $required ) {
		return array(
			'type'              => 'integer',
			'required'          => $required,
			'sanitize_callback' => 'absint',
			'validate_callback' => static function ( $value ) {
				return is_numeric( $value ) && (int) $value > 0 && (string) (int) $value === (string) $value;
			},
		);
	}

	private function bounded_integer_arg( $minimum, $maximum, $default_value ) {
		return array(
			'type'              => 'integer',
			'required'          => false,
			'default'           => $default_value,
			'sanitize_callback' => 'absint',
			'validate_callback' => static function ( $value ) use ( $minimum, $maximum ) {
				return is_numeric( $value ) && (int) $value >= $minimum && (int) $value <= $maximum && (string) (int) $value === (string) $value;
			},
		);
	}

	private function legacy_error( WP_Error $error ) {
		$data = $error->get_error_data();
		return $this->legacy_response( 0, $error->get_error_message(), array(), isset( $data['status'] ) ? (int) $data['status'] : 500 );
	}

	private function legacy_response( $status, $message, $result = array(), $http_status = 200 ) {
		return new WP_REST_Response(
			array(
				'status'   => (int) $status,
				'message'  => $message,
				'response' => array( 'result' => $result ),
			),
			$http_status
		);
	}
}
