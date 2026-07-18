<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options and cached dependency checks.
delete_option('bono_arm_api_enable_transactions');
delete_option('bono_arm_api_enable_member_activation');
delete_option('bono_arm_api_enable_member_delete');
delete_transient('bono_arm_api_tables_' . get_current_blog_id());
