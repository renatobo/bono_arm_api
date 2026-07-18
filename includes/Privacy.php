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
		$content .= '<p>' . esc_html__( 'The plugin stores only its feature-toggle settings and a capability-schema version. It does not send data to a third party and does not create its own request log. Site operators are responsible for documenting retention, access, and onward processing performed by clients that consume the API.', 'bono-arm-api' ) . '</p>';

		wp_add_privacy_policy_content(
			esc_html__( 'Bono API for ARMember', 'bono-arm-api' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}
