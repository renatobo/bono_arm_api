<?php
/*
Plugin Name: Bono API for ARMember
Plugin URI: https://github.com/renatobo/bono_arm_api
Description: Capability-controlled REST API access to ARMember payment logs and member management.
Version: 2.0.3
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Author: Renato Bonomini
Author URI: https://github.com/renatobo
Text Domain: bono-arm-api
Domain Path: /languages
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

GitHub Plugin URI: https://github.com/renatobo/bono_arm_api
Primary Branch: main
Release Asset: true
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BONO_ARM_API_VERSION', '2.0.3' );
define( 'BONO_ARM_API_FILE', __FILE__ );
define( 'BONO_ARM_API_PATH', plugin_dir_path( __FILE__ ) );
define( 'BONO_ARM_API_URL', plugin_dir_url( __FILE__ ) );
define( 'BONO_ARM_API_NAMESPACE', 'bono_armember/v1' );
define( 'BONO_ARM_API_V2_NAMESPACE', 'bono_armember/v2' );
define( 'BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS', 'bono_arm_api_enable_transactions' );
define( 'BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION', 'bono_arm_api_enable_member_activation' );
define( 'BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE', 'bono_arm_api_enable_member_delete' );
define( 'BONO_ARM_API_OPTION_SCHEMA_VERSION', 'bono_arm_api_schema_version' );
define( 'BONO_ARM_API_SETTINGS_PAGE', 'bono-arm-api-settings' );
define( 'BONO_ARM_API_MAX_PER_PAGE', 100 );
define( 'BONO_ARM_API_MAX_PAGE', 10000 );
define( 'BONO_ARM_API_TABLE_CHECK_TTL', 5 * MINUTE_IN_SECONDS );
define( 'BONO_ARM_API_CAP_READ_PAYMENTS', 'bono_arm_api_read_payments' );
define( 'BONO_ARM_API_CAP_ACTIVATE_MEMBERS', 'bono_arm_api_activate_members' );
define( 'BONO_ARM_API_CAP_DELETE_MEMBERS', 'bono_arm_api_delete_members' );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'BonoArmApi\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = BONO_ARM_API_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'BonoArmApi\\Capabilities', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BonoArmApi\\Capabilities', 'deactivate' ) );

BonoArmApi\Plugin::instance()->boot();
