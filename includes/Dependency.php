<?php
namespace BonoArmApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ARMember dependency detection.
 *
 * Two separate plugins satisfy this requirement: the premium "ARMember" (directory `armember`,
 * not distributed through WordPress.org) and "ARMember Lite" (directory `armember-membership`).
 * WordPress's `Requires Plugins:` header cannot express that choice. It resolves WordPress.org
 * slugs against installed plugin directories and requires every listed slug to be active, so
 * naming either one would make core deactivate this plugin on the sites running the other.
 * The check therefore happens here, at activation and on every admin screen.
 */
final class Dependency {
	/**
	 * Both copies define their directory constant at the top of the main plugin file, so the
	 * constant is set as soon as WordPress includes it. That is earlier and more reliable than
	 * any ARMember function, which may not be registered until a later hook.
	 */
	public static function is_met() {
		return defined( 'MEMBERSHIP_DIR_NAME' )
			|| defined( 'MEMBERSHIPLITE_DIR_NAME' )
			|| function_exists( 'arm_set_member_status' );
	}

	/**
	 * Blocks activation when neither ARMember copy is active, so capabilities are never granted
	 * on a site that cannot serve the API.
	 */
	public static function block_activation_when_unmet() {
		if ( self::is_met() ) {
			return;
		}

		wp_die(
			esc_html__( 'Bono API for ARMember requires ARMember or ARMember Lite to be installed and active.', 'bono-arm-api' ),
			esc_html__( 'Plugin dependency not met', 'bono-arm-api' ),
			array(
				'back_link' => true,
				'response'  => 200,
			)
		);
	}

	/**
	 * Covers the case the activation guard cannot: ARMember being deactivated while this plugin
	 * is already active.
	 */
	public static function render_admin_notice() {
		if ( self::is_met() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Bono API for ARMember requires ARMember or ARMember Lite to be active. Its REST endpoints return Service Unavailable until one of them is activated.', 'bono-arm-api' )
		);
	}
}
