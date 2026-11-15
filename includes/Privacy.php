<?php
namespace BonoArmApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Privacy {
	public static function exporters( $exporters ) {
		$exporters['bono-renewals'] = array(
			'exporter_friendly_name' => __( 'Renewals', 'bono-arm-api' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function erasers( $erasers ) {
		$erasers['bono-renewals'] = array(
			'eraser_friendly_name' => __( 'Renewals', 'bono-arm-api' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function export( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		$rows = $user ? ( new Infrastructure\Renewal_Repository() )->privacy_rows( $user->ID, $page ) : array();
		$data = array();
		foreach ( $rows as $row ) {
			// Operators receive their audit identifiers, not another member's payer details.
			$value = (int) $row['user_id'] === $user->ID ? $row : array_intersect_key( $row, array_flip( array( 'operation_id', 'actor_id', 'state', 'created_at', 'updated_at' ) ) );
			unset( $value['key_hash'], $value['command_hash'], $value['payment_hash'], $value['cycle_hash'] );
			$data[] = array(
				'group_id'    => 'bono-renewals',
				'group_label' => __( 'Renewals', 'bono-arm-api' ),
				'item_id'     => 'bono-renewal-' . $row['operation_id'],
				'data'        => array(
					array(
						'name'  => __( 'Renewal journal', 'bono-arm-api' ),
						'value' => wp_json_encode( $value ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 100,
		);
	}

	public static function erase( $email, $page = 1 ) {
		$user       = get_user_by( 'email', $email );
		$repository = new Infrastructure\Renewal_Repository();
		// Capture the stable operation list once; removing identifiers changes query pagination.
		$key = 'bono_arm_api_privacy_' . hash( 'sha256', $email );
		if ( 1 === (int) $page ) {
			$ids = array();
			for ( $batch = 1; $user; ++$batch ) {
				$rows = $repository->privacy_rows( $user->ID, $batch );
				$ids  = array_merge( $ids, array_column( $rows, 'operation_id' ) );
				if ( count( $rows ) < 100 ) {
					break; }
			}
			set_transient( $key, $ids, HOUR_IN_SECONDS );
		}
		$ids = get_transient( $key );
		if ( false === $ids && $page > 1 ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( __( 'The erasure session expired. Restart the privacy erasure request.', 'bono-arm-api' ) ),
				'done'           => true,
			);
		}
		$removed  = false;
		$retained = false;
		foreach ( array_slice( (array) $ids, max( 0, $page - 1 ) * 100, 100 ) as $id ) {
			$row  = $repository->get( $id );
			$lock = $row ? $repository->lock( 'member:' . $row['user_id'] ) : false;
			if ( ! $user || ! $lock ) {
				$retained = true;
				continue; }
			try {
				if ( $repository->erase_personal_details( $repository->get( $id ), $user->ID ) ) {
					$removed = true;
				} else {
					$retained = true; }
			} finally {
				$repository->unlock( $lock ); }
		}
		$done = $page * 100 >= count( (array) $ids );
		if ( $done ) {
			delete_transient( $key ); }
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained || $removed,
			'messages'       => array( __( 'Payment and cycle reservation identifiers are retained to prevent duplicate settlement. Unresolved or locked operations retain their details until reconciliation. ARMember payment records are managed separately.', 'bono-arm-api' ) ),
			'done'           => $done,
		);
	}

	public static function register_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'Bono API for ARMember adds authenticated REST API endpoints for ARMember payment and member administration data. Depending on the endpoint and requested context, responses can include WordPress usernames, email addresses, payment identifiers, payment amounts, dates, and administrative notes.', 'bono-arm-api' ) . '</p>';
		$content .= '<p>' . esc_html__( 'The plugin stores settings, capability grant history, and a durable renewal journal containing operator and member IDs, payment details, cycle state, and write outcomes. Renewal reservations are retained across uninstall to prevent duplicate settlement after reinstall. It does not send data to a third party. Site operators are responsible for journal access, reconciliation, retention, and onward processing performed by API clients.', 'bono-arm-api' ) . '</p>';

		wp_add_privacy_policy_content(
			esc_html__( 'Bono API for ARMember', 'bono-arm-api' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}
