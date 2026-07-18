<?php
namespace BonoArmApi;

use BonoArmApi\Infrastructure\Payment_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Abilities {
	public static function register_category() {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'bono-arm-api',
				array(
					'label'       => __( 'Bono API for ARMember', 'bono-arm-api' ),
					'description' => __( 'Read-only site and API status information.', 'bono-arm-api' ),
				)
			);
		}
	}

	public static function register( Payment_Repository $repository ) {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'bono-arm-api/get-status',
			array(
				'label'               => __( 'Get Bono API status', 'bono-arm-api' ),
				'description'         => __( 'Returns read-only availability and feature-toggle status.', 'bono-arm-api' ),
				'category'            => 'bono-arm-api',
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'armember_available' => array( 'type' => 'boolean' ),
						'payments_enabled'   => array( 'type' => 'boolean' ),
						'activation_enabled' => array( 'type' => 'boolean' ),
						'deletion_enabled'   => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => static function () use ( $repository ) {
					return array(
						'armember_available' => $repository->tables_exist(),
						'payments_enabled'   => (bool) get_option( BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS, false ),
						'activation_enabled' => (bool) get_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION, false ),
						'deletion_enabled'   => (bool) get_option( BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE, false ),
					);
				},
				'permission_callback' => static function () {
					return current_user_can( Capabilities::READ_PAYMENTS );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => false,
				),
			)
		);
	}
}
