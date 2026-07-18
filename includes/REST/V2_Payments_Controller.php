<?php
namespace BonoArmApi\REST;

use BonoArmApi\Infrastructure\Payment_Repository;
use BonoArmApi\Capabilities;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class V2_Payments_Controller extends WP_REST_Controller {
	private $repository;

	public function __construct( Payment_Repository $repository ) {
		$this->namespace  = BONO_ARM_API_V2_NAMESPACE;
		$this->rest_base  = 'payments';
		$this->repository = $repository;
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			)
		);
	}

	public function get_items_permissions_check( $request ) {
		if ( current_user_can( Capabilities::READ_PAYMENTS ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', __( 'You are not allowed to read payments.', 'bono-arm-api' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function get_items( $request ) {
		if ( ! get_option( BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS, false ) ) {
			return new WP_Error( 'bono_arm_api_disabled', __( 'The transactions API is disabled.', 'bono-arm-api' ), array( 'status' => 503 ) );
		}

		$result = $this->repository->get_cursor_page(
			(int) $request['after_invoice_id'],
			(int) $request['plan_id'],
			(int) $request['per_page'],
			(bool) $request['include_totals']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$context  = $request['context'];
		$payments = array();
		foreach ( $result['payments'] as $payment ) {
			$payments[] = $this->prepare_item_for_response( $payment, $request )->get_data();
		}

		$response = new WP_REST_Response(
			array(
				'payments'   => $payments,
				'pagination' => array(
					'per_page'    => (int) $request['per_page'],
					'next_cursor' => $result['next_cursor'],
					'has_more'    => $result['has_more'],
				),
				'context'    => $context,
			)
		);

		if ( null !== $result['total_count'] ) {
			$response->header( 'X-WP-Total', (int) $result['total_count'] );
			$response->header( 'X-WP-TotalPages', (int) ceil( $result['total_count'] / (int) $request['per_page'] ) );
		}

		return $response;
	}

	public function prepare_item_for_response( $item, $request ) {
		$data = array(
			'id'                 => (int) $item['id'],
			'invoice_id'         => (int) $item['arm_log_id'],
			'username'           => $item['username'],
			'paid_amount'        => $item['arm_paid_amount'],
			'payment_gateway'    => $item['arm_payment_gateway'],
			'payment_date'       => $item['arm_payment_date'],
			'transaction_status' => $item['arm_transaction_status'],
		);

		if ( 'edit' === $request['context'] ) {
			$data['payer_email'] = $item['arm_payer_email'];
			$data['notes']       = $item['notes'];
		}

		return rest_ensure_response( $data );
	}

	public function get_collection_params() {
		return array(
			'after_invoice_id' => array(
				'description'       => __( 'Return invoices with IDs greater than this cursor.', 'bono-arm-api' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'plan_id'          => array(
				'description'       => __( 'Limit results to an ARMember plan.', 'bono-arm-api' ),
				'type'              => 'integer',
				'minimum'           => 1,
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'per_page'         => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => BONO_ARM_API_MAX_PER_PAGE,
				'default'           => 50,
				'sanitize_callback' => 'absint',
			),
			'context'          => array(
				'type'    => 'string',
				'enum'    => array( 'view', 'edit' ),
				'default' => 'view',
			),
			'include_totals'   => array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->schema;
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bono-arm-payment',
			'type'       => 'object',
			'properties' => array(
				'id'                 => array(
					'type'     => 'integer',
					'context'  => array( 'view', 'edit' ),
					'readonly' => true,
				),
				'invoice_id'         => array(
					'type'     => 'integer',
					'context'  => array( 'view', 'edit' ),
					'readonly' => true,
				),
				'username'           => array(
					'type'     => 'string',
					'context'  => array( 'view', 'edit' ),
					'readonly' => true,
				),
				'payer_email'        => array(
					'type'     => 'string',
					'format'   => 'email',
					'context'  => array( 'edit' ),
					'readonly' => true,
				),
				'paid_amount'        => array(
					'type'     => 'string',
					'context'  => array( 'view', 'edit' ),
					'readonly' => true,
				),
				'payment_gateway'    => array(
					'type'     => 'string',
					'context'  => array( 'view', 'edit' ),
					'readonly' => true,
				),
				'payment_date'       => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'context'  => array( 'view', 'edit' ),
					'readonly' => true,
				),
				'notes'              => array(
					'type'     => 'string',
					'context'  => array( 'edit' ),
					'readonly' => true,
				),
				'transaction_status' => array(
					'type'     => 'string',
					'context'  => array( 'view', 'edit' ),
					'readonly' => true,
				),
			),
		);

		return $this->schema;
	}
}
