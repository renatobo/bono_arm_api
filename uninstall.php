<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

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
