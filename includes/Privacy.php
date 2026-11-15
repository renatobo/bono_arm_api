<?php
namespace BonoArmApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Privacy {
	public static function register_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'Bono API for ARMember adds authenticated REST API endpoints for ARMember payment and member administration data. Depending on the endpoint and requested context, responses can include WordPress usernames, email addresses, payment identifiers, payment amounts, dates, and administrative notes.', 'bono-arm-api' ) . '</p>';
		$content .= '<p>' . esc_html__( 'The plugin stores settings, capability grant history, and a durable sponsored-renewal journal containing operator and member IDs, sponsor payment details, cycle state, and write outcomes. Renewal reservations are retained across uninstall to prevent duplicate settlement after reinstall. It does not send data to a third party. Site operators are responsible for journal access, reconciliation, retention, and onward processing performed by API clients.', 'bono-arm-api' ) . '</p>';

		wp_add_privacy_policy_content(
			esc_html__( 'Bono API for ARMember', 'bono-arm-api' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}
