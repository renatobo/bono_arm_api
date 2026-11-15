<?php
namespace BonoArmApi\REST;

use BonoArmApi\Capabilities;
use BonoArmApi\Renewals\Service;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class V2_Renewals_Controller extends WP_REST_Controller {
	private $service;

	public function __construct( Service $service ) {
		$this->namespace = BONO_ARM_API_V2_NAMESPACE;
		$this->service   = $service;
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/members/(?P<user_id>[\d]+)/renewals',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'renew' ),
					'permission_callback' => array( $this, 'can_renew' ),
					'args'                => array(
						'user_id' => array(
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/renewals/(?P<operation_id>[a-f0-9-]{36})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_renew' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/renewals/(?P<operation_id>[a-f0-9-]{36})/resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resolve' ),
				'permission_callback' => array( $this, 'can_resolve' ),
			)
		);
	}

	public function resolve( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! $request->is_json_content_type() || ! is_array( $body ) || array_diff( array_keys( $body ), array( 'cycle_stands', 'payment_stands', 'confirm_reconciled' ) ) || true !== ( $body['confirm_reconciled'] ?? null ) || ! is_bool( $body['cycle_stands'] ?? null ) || ! is_bool( $body['payment_stands'] ?? null ) ) {
			return $this->invalid();
		}
		$result = $this->service->resolve( $request['operation_id'], $body['cycle_stands'], $body['payment_stands'] );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200, array( 'Cache-Control' => 'no-store' ) );
	}

	public function can_resolve() {
		$allowed = $this->can_renew();
		return true === $allowed && current_user_can( 'manage_options' ) ? true : new WP_Error( 'rest_forbidden', __( 'You are not allowed to perform this action.', 'bono-arm-api' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function can_renew() {
		return current_user_can( Capabilities::RENEW_MEMBERS ) && current_user_can( Capabilities::ACTIVATE_MEMBERS ) ? true : new WP_Error( 'rest_forbidden', __( 'You are not allowed to perform this action.', 'bono-arm-api' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function get_item_schema() {
		return array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'renewal-command',
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'plan_id', 'payment' ),
			'properties'           => array(
				'plan_id'           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'dry_run'           => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'suspension_reason' => array(
					'type'        => 'string',
					'enum'        => array( 'nonpayment' ),
					'description' => __( 'Operator-confirmed cause of the target plan suspension. Does not override unrelated restrictions.', 'bono-arm-api' ),
				),
				'expected_state'    => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'payment'           => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'mode' ),
					'properties'           => array(
						'mode'           => array(
							'type' => 'string',
							'enum' => array( 'create', 'existing' ),
						),
						'reference'      => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 191,
							'pattern'   => '^[^\x00-\x20\x7f]+$',
						),
						'payer_name'     => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 200,
						),
						'amount'         => array(
							'type'    => 'string',
							'pattern' => '^(0|[1-9][0-9]{0,9})(\.[0-9]{1,6})?$',
						),
						'currency'       => array(
							'type'    => 'string',
							'pattern' => '^[A-Z]{3}$',
						),
						'paid_at'        => array(
							'type'    => 'string',
							'format'  => 'date-time',
							'pattern' => '(Z|[+-][0-9]{2}:[0-9]{2})$',
						),
						'payment_log_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			),
		);
	}

	public function renew( WP_REST_Request $request ) {
		if ( ! get_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_RENEWALS, false ) || ! get_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION, false ) ) {
			return $this->disabled();
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! $request->is_json_content_type() ) {
			return $this->invalid();
		}
		$valid = rest_validate_value_from_schema( $body, $this->get_item_schema(), 'body' );
		if ( is_wp_error( $valid ) ) {
			return $this->invalid( $valid->get_error_message() );
		}
		$payment = $body['payment'];
		foreach ( $payment as $value ) {
			if ( is_string( $value ) && preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
				return $this->invalid();
			}
		}
		$fields = 'create' === $payment['mode'] ? array( 'mode', 'reference', 'payer_name', 'amount', 'currency', 'paid_at' ) : array( 'mode', 'payment_log_id' );
		if ( array_diff( $fields, array_keys( $payment ) ) || array_diff( array_keys( $payment ), $fields ) ) {
			return $this->invalid();
		}
		// WordPress schema integers also accept numeric strings. This contract requires JSON types.
		if ( ! is_int( $body['plan_id'] ) || ( isset( $body['dry_run'] ) && ! is_bool( $body['dry_run'] ) ) || ( isset( $payment['payment_log_id'] ) && ! is_int( $payment['payment_log_id'] ) ) ) {
			return $this->invalid();
		}
		if ( 'create' === $payment['mode'] ) {
			if ( (float) $payment['amount'] <= 0 || sanitize_text_field( $payment['payer_name'] ) !== $payment['payer_name'] || '' === trim( $payment['payer_name'] ) ) {
				return $this->invalid();
			}
			try {
				$date   = new \DateTimeImmutable( $payment['paid_at'] );
				$errors = \DateTimeImmutable::getLastErrors();
				if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
					return $this->invalid();
				}
				$payment['paid_at'] = $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_RFC3339 );
			} catch ( \Exception $exception ) {
				return $this->invalid();
			}
			$payment['amount'] = false !== strpos( $payment['amount'], '.' ) ? rtrim( rtrim( $payment['amount'], '0' ), '.' ) : $payment['amount'];
		}
		ksort( $payment );
		$command = array(
			'plan_id' => $body['plan_id'],
			'payment' => $payment,
		);
		if ( isset( $body['suspension_reason'] ) ) {
			$command['suspension_reason'] = $body['suspension_reason'];
		}
		$dry_run = $body['dry_run'] ?? true;
		$key     = $request->get_header( 'idempotency-key' );
		if ( ! $dry_run && ( ! is_string( $key ) || ! preg_match( '/^[\x21-\x7e]{16,128}$/D', $key ) ) ) {
			return $this->invalid();
		}
		$result = $this->service->run( (int) $request['user_id'], $command, $dry_run, $body['expected_state'] ?? '', $key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = new WP_REST_Response( $result, $dry_run || $result['idempotent_replay'] ? 200 : 201 );
		$response->header( 'Cache-Control', 'no-store' );
		if ( ! $dry_run ) {
			$response->header( 'Location', rest_url( $this->namespace . '/renewals/' . $result['operation_id'] ) );
		}
		return $response;
	}

	public function status( WP_REST_Request $request ) {
		$result = $this->service->status( $request['operation_id'] );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200, array( 'Cache-Control' => 'no-store' ) );
	}

	/** REST error conversion otherwise discards HTTP retry/cache headers. */
	public function response_headers( $response, $server, $request ) {
		if ( preg_match( '#^/' . preg_quote( $this->namespace, '#' ) . '/(?:members/\d+/renewals|renewals/[a-f0-9-]{36}(?:/resolve)?)$#D', $request->get_route() ) ) {
			$response->header( 'Cache-Control', 'no-store' );
			$data = $response->get_data();
			if ( isset( $data['code'] ) && 'bono_arm_api_renewal_in_progress' === $data['code'] ) {
				$response->header( 'Retry-After', '5' );
			}
		}
		return $response;
	}

	private function disabled() {
		return new WP_Error( 'bono_arm_api_feature_disabled', __( 'Renewal and member activation must both be enabled.', 'bono-arm-api' ), array( 'status' => 503 ) );
	}

	private function invalid( $message = '' ) {
		return new WP_Error( 'rest_invalid_param', $message ? $message : __( 'Invalid renewal command, preview token, or idempotency key.', 'bono-arm-api' ), array( 'status' => 400 ) );
	}
}
