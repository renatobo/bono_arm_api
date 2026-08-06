<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes every option, transient, and capability the plugin created on one site.
 */
function bono_arm_api_uninstall_site() {
	delete_option( 'bono_arm_api_enable_transactions' );
	delete_option( 'bono_arm_api_enable_member_activation' );
	delete_option( 'bono_arm_api_enable_member_delete' );
	delete_option( 'bono_arm_api_schema_version' );
	delete_transient( 'bono_arm_api_tables_' . get_current_blog_id() );

	$administrator = get_role( 'administrator' );
	if ( $administrator ) {
		$administrator->remove_cap( 'bono_arm_api_read_payments' );
		$administrator->remove_cap( 'bono_arm_api_activate_members' );
		$administrator->remove_cap( 'bono_arm_api_delete_members' );
	}
}

if ( is_multisite() ) {
	$bono_arm_api_batch_size = 100;
	$bono_arm_api_offset     = 0;

	do {
		$bono_arm_api_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $bono_arm_api_batch_size,
				'offset' => $bono_arm_api_offset,
			)
		);

		foreach ( $bono_arm_api_site_ids as $bono_arm_api_site_id ) {
			switch_to_blog( $bono_arm_api_site_id );
			bono_arm_api_uninstall_site();
			restore_current_blog();
		}

		$bono_arm_api_offset += $bono_arm_api_batch_size;
		$bono_arm_api_fetched = count( $bono_arm_api_site_ids );
	} while ( $bono_arm_api_fetched === $bono_arm_api_batch_size );

	unset( $bono_arm_api_batch_size, $bono_arm_api_offset, $bono_arm_api_fetched, $bono_arm_api_site_ids, $bono_arm_api_site_id );
} else {
	bono_arm_api_uninstall_site();
}
