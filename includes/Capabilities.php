<?php
namespace BonoArmApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {
	const READ_PAYMENTS    = 'bono_arm_api_read_payments';
	const ACTIVATE_MEMBERS = 'bono_arm_api_activate_members';
	const DELETE_MEMBERS   = 'bono_arm_api_delete_members';

	public static function all() {
		return array(
			self::READ_PAYMENTS,
			self::ACTIVATE_MEMBERS,
			self::DELETE_MEMBERS,
		);
	}

	public static function activate() {
		self::grant_to_administrators();
		update_option( BONO_ARM_API_OPTION_SCHEMA_VERSION, BONO_ARM_API_VERSION, false );
	}

	public static function deactivate() {
		self::remove_from_administrators();
		delete_option( BONO_ARM_API_OPTION_SCHEMA_VERSION );
	}

	public static function maybe_upgrade() {
		if ( BONO_ARM_API_VERSION === get_option( BONO_ARM_API_OPTION_SCHEMA_VERSION ) ) {
			return;
		}

		self::activate();
	}

	public static function grant_to_administrators() {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( self::all() as $capability ) {
			$role->add_cap( $capability );
		}
	}

	public static function remove_from_administrators() {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( self::all() as $capability ) {
			$role->remove_cap( $capability );
		}
	}
}
