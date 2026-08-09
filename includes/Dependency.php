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
	 * Reports the dependency to the site owner.
	 *
	 * Activation is deliberately not blocked. Blocking would break `wp plugin activate` for
	 * WP-CLI, provisioning scripts, and the official Plugin Check, all of which activate a
	 * plugin standalone. The dependency is enforced by withholding capabilities instead, which
	 * is the property that actually matters: the endpoints already return Service Unavailable
	 * without ARMember, so an activated-but-ungranted plugin has no reachable surface.
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
