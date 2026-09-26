<?php
namespace BonoArmApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {
	const READ_PAYMENTS      = 'bono_arm_api_read_payments';
	const ACTIVATE_MEMBERS   = 'bono_arm_api_activate_members';
	const DELETE_MEMBERS     = 'bono_arm_api_delete_members';
	const READ_PAYER_DETAILS = 'bono_arm_api_read_payer_details';

	/**
	 * Capabilities every release before 2.2.0 granted on activation. Installs that recorded a
	 * schema version but no granted list already received these, so they are not granted again.
	 */
	const LEGACY = array(
		self::READ_PAYMENTS,
		self::ACTIVATE_MEMBERS,
		self::DELETE_MEMBERS,
	);

	public static function all() {
		return array(
			self::READ_PAYMENTS,
			self::ACTIVATE_MEMBERS,
			self::DELETE_MEMBERS,
			self::READ_PAYER_DETAILS,
		);
	}

	public static function activate() {
		self::grant_to_administrators( self::all() );
		update_option( BONO_ARM_API_OPTION_CAPS_GRANTED, self::all(), true );
		update_option( BONO_ARM_API_OPTION_SCHEMA_VERSION, BONO_ARM_API_VERSION, true );
	}

	public static function deactivate() {
		self::remove_from_administrators();
		delete_option( BONO_ARM_API_OPTION_SCHEMA_VERSION );
		delete_option( BONO_ARM_API_OPTION_CAPS_GRANTED );
	}

	/**
	 * Grants each capability to administrators at most once.
	 *
	 * Upgrades grant only capabilities introduced since the last grant, so an operator who
	 * removed one from the administrator role keeps it removed across plugin updates.
	 */
	public static function maybe_upgrade() {
		// Without ARMember the plugin has no reachable surface, so it gets no capabilities.
		// This also grants them on the first load after ARMember is activated, which is the
		// path activation cannot cover when this plugin was enabled first.
		if ( ! Dependency::is_met() ) {
			return;
		}

		$schema_version = get_option( BONO_ARM_API_OPTION_SCHEMA_VERSION );

		if ( BONO_ARM_API_VERSION === $schema_version ) {
			return;
		}

		$granted = get_option( BONO_ARM_API_OPTION_CAPS_GRANTED );

		if ( ! is_array( $granted ) ) {
			$granted = $schema_version ? self::LEGACY : array();
		}

		$missing = array_values( array_diff( self::all(), $granted ) );

		if ( $missing ) {
			self::grant_to_administrators( $missing );
		}

		update_option( BONO_ARM_API_OPTION_CAPS_GRANTED, array_values( array_unique( array_merge( $granted, $missing ) ) ), true );
		update_option( BONO_ARM_API_OPTION_SCHEMA_VERSION, BONO_ARM_API_VERSION, true );
	}

	public static function grant_to_administrators( $capabilities = null ) {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( null === $capabilities ? self::all() : $capabilities as $capability ) {
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
