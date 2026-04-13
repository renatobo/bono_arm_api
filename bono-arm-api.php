<?php
/*
Plugin Name: Bono API for ARMember
Plugin URI: https://github.com/renatobo/bono_arm_api
Description: Admin-only REST API access to ARMember payment logs, member activation, and guarded member deletion.
Version: 1.1.9
Requires at least: 5.0
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

if (!defined('ABSPATH')) {
    exit;
}

define('BONO_ARM_API_VERSION', '1.1.9');
define('BONO_ARM_API_NAMESPACE', 'bono_armember/v1');
define('BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS', 'bono_arm_api_enable_transactions');
define('BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION', 'bono_arm_api_enable_member_activation');
define('BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE', 'bono_arm_api_enable_member_delete');
define('BONO_ARM_API_SETTINGS_PAGE', 'bono-arm-api-settings');
define('BONO_ARM_API_MAX_PER_PAGE', 100);

add_action('admin_menu', 'bono_arm_api_add_settings_page');
add_action('admin_init', 'bono_arm_api_register_settings');
add_action('plugins_loaded', 'bono_arm_api_load_textdomain');
add_action('rest_api_init', 'bono_arm_api_register_routes');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bono_arm_api_add_plugin_action_links');

/**
 * Load plugin translations.
 */
function bono_arm_api_load_textdomain() {
    load_plugin_textdomain('bono-arm-api', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

/**
 * Add the plugin settings page.
 */
function bono_arm_api_add_settings_page() {
    add_options_page(
        __('Bono ARM API Settings', 'bono-arm-api'),
        __('Bono ARM API', 'bono-arm-api'),
        'manage_options',
        BONO_ARM_API_SETTINGS_PAGE,
        'bono_arm_api_render_settings_page'
    );
}

/**
 * Add a Settings link on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function bono_arm_api_add_plugin_action_links($links) {
    $settings_url = admin_url('options-general.php?page=' . BONO_ARM_API_SETTINGS_PAGE);

    array_unshift(
        $links,
        sprintf(
            '<a href="%s">%s</a>',
            esc_url($settings_url),
            esc_html__('Settings', 'bono-arm-api')
        )
    );

    return $links;
}

/**
 * Register plugin settings.
 */
function bono_arm_api_register_settings() {
    register_setting(
        'bono_arm_api_settings_group',
        BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS,
        array(
            'type' => 'boolean',
            'sanitize_callback' => 'bono_arm_api_sanitize_checkbox',
            'default' => false,
        )
    );

    register_setting(
        'bono_arm_api_settings_group',
        BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION,
        array(
            'type' => 'boolean',
            'sanitize_callback' => 'bono_arm_api_sanitize_checkbox',
            'default' => false,
        )
    );

    register_setting(
        'bono_arm_api_settings_group',
        BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE,
        array(
            'type' => 'boolean',
            'sanitize_callback' => 'bono_arm_api_sanitize_checkbox',
            'default' => false,
        )
    );
}

/**
 * Sanitize checkbox-style values into a boolean.
 *
 * @param mixed $value Submitted option value.
 * @return bool
 */
function bono_arm_api_sanitize_checkbox($value) {
    return !empty($value);
}

/**
 * Return whether the transactions endpoint is enabled.
 *
 * @return bool
 */
function bono_arm_api_is_transactions_enabled() {
    return (bool) get_option(BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS, false);
}

/**
 * Return whether the member activation endpoint is enabled.
 *
 * @return bool
 */
function bono_arm_api_is_member_activation_enabled() {
    return (bool) get_option(BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION, false);
}

/**
 * Return whether the member deletion endpoint is enabled.
 *
 * @return bool
 */
function bono_arm_api_is_member_delete_enabled() {
    return (bool) get_option(BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE, false);
}

/**
 * Return whether the current user has the administrator role.
 *
 * @return bool
 */
function bono_arm_api_current_user_is_administrator() {
    $user = wp_get_current_user();

    return $user instanceof WP_User && in_array('administrator', (array) $user->roles, true);
}

/**
 * Return the JSON structure used by the endpoint.
 *
 * @param int    $status Response status flag.
 * @param string $message Response message.
 * @param array  $result Response result payload.
 * @return WP_REST_Response
 */
function bono_arm_api_rest_response($status, $message, $result = array()) {
    return rest_ensure_response(
        array(
            'status' => (int) $status,
            'message' => $message,
            'response' => array(
                'result' => $result,
            ),
        )
    );
}

/**
 * Return the ARMember table names used by the endpoint.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 * @return array<string, string>
 */
function bono_arm_api_get_armember_tables() {
    global $wpdb;

    return array(
        'payment_log' => $wpdb->prefix . 'arm_payment_log',
        'members' => $wpdb->prefix . 'arm_members',
    );
}

/**
 * Check whether the required ARMember tables are available.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 * @return bool
 */
function bono_arm_api_armember_tables_exist() {
    global $wpdb;

    $tables = bono_arm_api_get_armember_tables();

    foreach ($tables as $table_name) {
        $existing_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)));

        if ($existing_table !== $table_name) {
            return false;
        }
    }

    return true;
}

/**
 * Return the loaded ARMember members manager object when available.
 *
 * @return object|null
 */
function bono_arm_api_get_armember_members_manager() {
    global $arm_members_class;

    if (is_object($arm_members_class)) {
        return $arm_members_class;
    }

    return null;
}

/**
 * Return whether the loaded ARMember members manager exposes delete cleanup methods.
 *
 * @param object|null $arm_members_manager ARMember members manager instance.
 * @return bool
 */
function bono_arm_api_armember_members_manager_can_delete($arm_members_manager = null) {
    if (!is_object($arm_members_manager)) {
        $arm_members_manager = bono_arm_api_get_armember_members_manager();
    }

    return is_object($arm_members_manager)
        && method_exists($arm_members_manager, 'arm_before_delete_user_action')
        && method_exists($arm_members_manager, 'arm_after_deleted_user_action');
}

/**
 * Return whether ARMember's delete lifecycle hooks are currently attached.
 *
 * @param object|null $arm_members_manager ARMember members manager instance.
 * @return bool
 */
function bono_arm_api_armember_delete_hooks_are_active($arm_members_manager = null) {
    if (!is_object($arm_members_manager)) {
        $arm_members_manager = bono_arm_api_get_armember_members_manager();
    }

    if (!bono_arm_api_armember_members_manager_can_delete($arm_members_manager)) {
        return false;
    }

    return false !== has_action('delete_user', array($arm_members_manager, 'arm_before_delete_user_action'))
        && false !== has_action('deleted_user', array($arm_members_manager, 'arm_after_deleted_user_action'));
}

/**
 * Render the plugin settings page.
 */
function bono_arm_api_render_settings_page() {
    $transactions_api_enabled = bono_arm_api_is_transactions_enabled();
    $member_activation_api_enabled = bono_arm_api_is_member_activation_enabled();
    $member_delete_api_enabled = bono_arm_api_is_member_delete_enabled();
    $site_url = untrailingslashit(get_site_url());
    $rest_root_url = $site_url . '/wp-json';
    $project_url = 'https://github.com/renatobo/bono_arm_api';
    $release_notes_url = $project_url . '/releases/tag/v' . rawurlencode(BONO_ARM_API_VERSION);
    $author_url = 'https://github.com/renatobo';
    $git_updater_url = 'https://github.com/afragen/git-updater';
    $openapi_spec_url = plugins_url('docs/bono-arm-api-openapi.json', __FILE__);
    $postman_collection_url = plugins_url('docs/bono-arm-api-postman-collection.json', __FILE__);
    $banner_url = plugins_url('assets/bono-arm-api-settings-banner.svg', __FILE__);
    $transactions_endpoint_url = rest_url(BONO_ARM_API_NAMESPACE . '/arm_payments_log');
    $member_activation_endpoint_template = '/wp-json/' . BONO_ARM_API_NAMESPACE . '/members/{user_id}/activate';
    $member_activation_endpoint_example = rest_url(BONO_ARM_API_NAMESPACE . '/members/123/activate');
    $member_delete_endpoint_template = '/wp-json/' . BONO_ARM_API_NAMESPACE . '/members/{user_id}/delete';
    $member_delete_endpoint_example = rest_url(BONO_ARM_API_NAMESPACE . '/members/123/delete');
    $example_basic = $transactions_endpoint_url . '?arm_invoice_id_gt=1450';
    $example_filtered = $transactions_endpoint_url . '?arm_invoice_id_gt=1450&arm_plan_id=2&arm_page=2&arm_perpage=25';
    $example_curl = 'curl -u your_username:your_app_password "' . $example_basic . '"';
    $member_activation_curl = "curl -u your_username:your_app_password -X POST -H \"Content-Type: application/json\" -d '{\"send_email\":true}' \"" . $member_activation_endpoint_example . '"';
    $member_delete_curl = 'curl -u your_username:your_app_password -X POST "' . $member_delete_endpoint_example . '"';
    $armember_available = bono_arm_api_armember_tables_exist();
    ?>
    <div class="wrap">
        <div class="bono-arm-api-admin">
            <div class="bono-arm-api-hero">
                <img
                    src="<?php echo esc_url($banner_url); ?>"
                    alt="<?php esc_attr_e('Bono ARM API settings banner', 'bono-arm-api'); ?>"
                    class="bono-arm-api-hero-image"
                />
            </div>

            <div class="bono-arm-api-meta">
                <a href="<?php echo esc_url($project_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Plugin Repository', 'bono-arm-api'); ?>
                </a>
                <span>
                    <?php
                    printf(
                        /* translators: %s: plugin version. */
                        esc_html__('Version %s', 'bono-arm-api'),
                        esc_html(BONO_ARM_API_VERSION)
                    );
                    ?>
                </span>
                <a href="<?php echo esc_url($release_notes_url); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr(sprintf(__('Release notes for version %s', 'bono-arm-api'), BONO_ARM_API_VERSION)); ?>">
                    <?php esc_html_e('Release notes', 'bono-arm-api'); ?>
                </a>
                <a href="<?php echo esc_url($author_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Renato Bonomini on GitHub', 'bono-arm-api'); ?>
                </a>
                <a href="<?php echo esc_url($git_updater_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('GitHub Updates (Git Updater)', 'bono-arm-api'); ?>
                </a>
            </div>

            <div class="bono-arm-api-headline">
                <h1><?php esc_html_e('Bono ARM API Settings', 'bono-arm-api'); ?></h1>
                <p class="bono-arm-api-intro">
                    <?php esc_html_e('Control the availability of the custom ARMember transactions and member-management API surface, review the supported request parameters, and share administrator-authenticated access with external systems.', 'bono-arm-api'); ?>
                </p>
                <p class="bono-arm-api-intro bono-arm-api-intro-secondary">
                    <?php esc_html_e('This plugin is designed for WordPress integrations that need filtered ARMember payment logs without exposing the endpoint publicly.', 'bono-arm-api'); ?>
                </p>
                <p class="bono-arm-api-intro bono-arm-api-intro-secondary">
                    <?php esc_html_e('Distribution is dual-channel: GitHub releases are the primary update channel through Git Updater, and WordPress.org remains the secondary channel when that listing is available.', 'bono-arm-api'); ?>
                </p>
            </div>

            <?php settings_errors(); ?>

            <?php if (!$armember_available) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php esc_html_e('ARMember payment tables were not found. The endpoint stays disabled until ARMember is installed and active.', 'bono-arm-api'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper bono-arm-api-tabs" role="tablist" aria-label="<?php esc_attr_e('Bono ARM API sections', 'bono-arm-api'); ?>">
                <a href="#api" class="nav-tab bono-arm-api-tab nav-tab-active" role="tab" aria-selected="true" data-panel="api">
                    <?php esc_html_e('Transactions API', 'bono-arm-api'); ?>
                </a>
                <a href="#specs" class="nav-tab bono-arm-api-tab" role="tab" aria-selected="false" data-panel="specs">
                    <?php esc_html_e('API Specs', 'bono-arm-api'); ?>
                </a>
                <a href="#fields" class="nav-tab bono-arm-api-tab" role="tab" aria-selected="false" data-panel="fields">
                    <?php esc_html_e('Request fields', 'bono-arm-api'); ?>
                </a>
                <a href="#passwords" class="nav-tab bono-arm-api-tab" role="tab" aria-selected="false" data-panel="passwords">
                    <?php esc_html_e('Application Passwords', 'bono-arm-api'); ?>
                </a>
            </nav>

            <form method="post" action="options.php" class="bono-arm-api-shell">
                <?php settings_fields('bono_arm_api_settings_group'); ?>
                <?php do_settings_sections('bono_arm_api_settings_group'); ?>

                <section class="bono-arm-api-panel is-active" id="api" data-panel="api" role="tabpanel">
                    <div class="bono-arm-api-panel-header">
                        <div>
                            <h2><?php esc_html_e('Transactions endpoint', 'bono-arm-api'); ?></h2>
                            <p>
                                <?php
                                printf(
                                    /* translators: %s: REST namespace. */
                                    esc_html__('Gate the custom %s REST endpoint without changing its authentication requirements or response shape.', 'bono-arm-api'),
                                    esc_html(BONO_ARM_API_NAMESPACE)
                                );
                                ?>
                            </p>
                        </div>
                    </div>

                    <div class="bono-arm-api-card bono-arm-api-card-accent">
                        <div class="bono-arm-api-switch-row">
                            <div>
                                <h3><?php esc_html_e('List of Transactions', 'bono-arm-api'); ?></h3>
                                <p>
                                    <?php
                                    printf(
                                        /* translators: %s: REST route. */
                                        esc_html__('Enable or disable the protected REST endpoint under %s.', 'bono-arm-api'),
                                        '/wp-json/' . esc_html(BONO_ARM_API_NAMESPACE) . '/arm_payments_log'
                                    );
                                    ?>
                                </p>
                            </div>
                            <label class="bono-arm-api-toggle">
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS); ?>"
                                    value="1"
                                    <?php checked(true, $transactions_api_enabled, true); ?>
                                />
                                <span><?php esc_html_e('Enable transactions API', 'bono-arm-api'); ?></span>
                            </label>
                        </div>

                        <div class="bono-arm-api-grid bono-arm-api-grid-two">
                            <div class="bono-arm-api-code-card">
                                <strong><?php esc_html_e('Namespace', 'bono-arm-api'); ?></strong>
                                <code>/wp-json/<?php echo esc_html(BONO_ARM_API_NAMESPACE); ?></code>
                            </div>
                            <div class="bono-arm-api-code-card">
                                <strong><?php esc_html_e('Authentication', 'bono-arm-api'); ?></strong>
                                <span><?php esc_html_e('Administrator role required with WordPress credentials or Application Passwords.', 'bono-arm-api'); ?></span>
                            </div>
                        </div>

                        <div class="bono-arm-api-route-list">
                            <strong><?php esc_html_e('Route', 'bono-arm-api'); ?></strong>
                            <code>GET <?php echo esc_html($transactions_endpoint_url); ?></code>
                        </div>

                        <div class="bono-arm-api-example-grid">
                            <div class="bono-arm-api-example">
                                <strong><?php esc_html_e('Basic request', 'bono-arm-api'); ?></strong>
                                <code id="bono-arm-api-example-basic"><?php echo esc_html($example_basic); ?></code>
                                <button type="button" class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-example-basic');">
                                    <?php esc_html_e('Copy', 'bono-arm-api'); ?>
                                </button>
                            </div>
                            <div class="bono-arm-api-example">
                                <strong><?php esc_html_e('Authenticated curl example', 'bono-arm-api'); ?></strong>
                                <code id="bono-arm-api-example-curl"><?php echo esc_html($example_curl); ?></code>
                                <button type="button" class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-example-curl');">
                                    <?php esc_html_e('Copy', 'bono-arm-api'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="bono-arm-api-card bono-arm-api-card-accent">
                        <div class="bono-arm-api-switch-row">
                            <div>
                                <h3><?php esc_html_e('Delete Member', 'bono-arm-api'); ?></h3>
                                <p>
                                    <?php
                                    printf(
                                        /* translators: %s: REST route. */
                                        esc_html__('Enable or disable the protected REST endpoint under %s.', 'bono-arm-api'),
                                        esc_html($member_delete_endpoint_template)
                                    );
                                    ?>
                                </p>
                            </div>
                            <label class="bono-arm-api-toggle">
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE); ?>"
                                    value="1"
                                    <?php checked(true, $member_delete_api_enabled, true); ?>
                                />
                                <span><?php esc_html_e('Enable member delete API', 'bono-arm-api'); ?></span>
                            </label>
                        </div>

                        <div class="bono-arm-api-route-list">
                            <strong><?php esc_html_e('Route', 'bono-arm-api'); ?></strong>
                            <code>POST <?php echo esc_html($member_delete_endpoint_example); ?></code>
                        </div>

                        <div class="bono-arm-api-grid bono-arm-api-grid-two">
                            <div class="bono-arm-api-code-card">
                                <strong><?php esc_html_e('Path parameter', 'bono-arm-api'); ?></strong>
                                <code>user_id</code>
                                <span><?php esc_html_e('WordPress user ID to delete together with ARMember member data.', 'bono-arm-api'); ?></span>
                            </div>
                            <div class="bono-arm-api-code-card">
                                <strong><?php esc_html_e('Delete behavior', 'bono-arm-api'); ?></strong>
                                <span><?php esc_html_e('Uses wp_delete_user() and ARMember cleanup hooks when available, with a guarded fallback to ARMember’s explicit pre/post-delete methods.', 'bono-arm-api'); ?></span>
                            </div>
                        </div>

                        <div class="bono-arm-api-example-grid">
                            <div class="bono-arm-api-example">
                                <strong><?php esc_html_e('Delete request', 'bono-arm-api'); ?></strong>
                                <code id="bono-arm-api-example-delete"><?php echo esc_html($member_delete_endpoint_example); ?></code>
                                <button type="button" class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-example-delete');">
                                    <?php esc_html_e('Copy', 'bono-arm-api'); ?>
                                </button>
                            </div>
                            <div class="bono-arm-api-example">
                                <strong><?php esc_html_e('Authenticated curl example', 'bono-arm-api'); ?></strong>
                                <code id="bono-arm-api-example-delete-curl"><?php echo esc_html($member_delete_curl); ?></code>
                                <button type="button" class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-example-delete-curl');">
                                    <?php esc_html_e('Copy', 'bono-arm-api'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="bono-arm-api-card bono-arm-api-card-accent">
                        <div class="bono-arm-api-switch-row">
                            <div>
                                <h3><?php esc_html_e('Activate Member', 'bono-arm-api'); ?></h3>
                                <p>
                                    <?php
                                    printf(
                                        /* translators: %s: REST route. */
                                        esc_html__('Enable or disable the protected REST endpoint under %s.', 'bono-arm-api'),
                                        esc_html($member_activation_endpoint_template)
                                    );
                                    ?>
                                </p>
                            </div>
                            <label class="bono-arm-api-toggle">
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION); ?>"
                                    value="1"
                                    <?php checked(true, $member_activation_api_enabled, true); ?>
                                />
                                <span><?php esc_html_e('Enable member activation API', 'bono-arm-api'); ?></span>
                            </label>
                        </div>

                        <div class="bono-arm-api-route-list">
                            <strong><?php esc_html_e('Route', 'bono-arm-api'); ?></strong>
                            <code>POST <?php echo esc_html($member_activation_endpoint_example); ?></code>
                        </div>

                        <div class="bono-arm-api-grid bono-arm-api-grid-two">
                            <div class="bono-arm-api-code-card">
                                <strong><?php esc_html_e('Path parameter', 'bono-arm-api'); ?></strong>
                                <code>user_id</code>
                                <span><?php esc_html_e('WordPress user ID to activate in ARMember.', 'bono-arm-api'); ?></span>
                            </div>
                            <div class="bono-arm-api-code-card">
                                <strong><?php esc_html_e('Optional JSON body', 'bono-arm-api'); ?></strong>
                                <code>{"send_email": true}</code>
                                <span><?php esc_html_e('Send ARMember’s manual activation email after activation.', 'bono-arm-api'); ?></span>
                            </div>
                        </div>

                        <div class="bono-arm-api-example-grid">
                            <div class="bono-arm-api-example">
                                <strong><?php esc_html_e('Activation request', 'bono-arm-api'); ?></strong>
                                <code id="bono-arm-api-example-activate"><?php echo esc_html($member_activation_endpoint_example); ?></code>
                                <button type="button" class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-example-activate');">
                                    <?php esc_html_e('Copy', 'bono-arm-api'); ?>
                                </button>
                            </div>
                            <div class="bono-arm-api-example">
                                <strong><?php esc_html_e('Authenticated curl example', 'bono-arm-api'); ?></strong>
                                <code id="bono-arm-api-example-activate-curl"><?php echo esc_html($member_activation_curl); ?></code>
                                <button type="button" class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-example-activate-curl');">
                                    <?php esc_html_e('Copy', 'bono-arm-api'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="bono-arm-api-card">
                        <div class="bono-arm-api-panel-copy">
                            <h3><?php esc_html_e('Behavior', 'bono-arm-api'); ?></h3>
                            <p>
                                <?php esc_html_e('The endpoint returns only successful ARMember transactions, supports invoice-threshold filtering plus pagination, and keeps the existing JSON envelope used by external callers.', 'bono-arm-api'); ?>
                            </p>
                        </div>

                        <div class="bono-arm-api-grid bono-arm-api-grid-two">
                            <div class="bono-arm-api-code-card">
                                <strong><?php esc_html_e('Required query parameter', 'bono-arm-api'); ?></strong>
                                <code>arm_invoice_id_gt</code>
                                <span><?php esc_html_e('Fetch only records with an invoice ID greater than the value provided.', 'bono-arm-api'); ?></span>
                            </div>
                            <div class="bono-arm-api-code-card">
                                <strong><?php esc_html_e('Optional filters', 'bono-arm-api'); ?></strong>
                                <code>arm_plan_id</code>
                                <code>arm_page</code>
                                <code>arm_perpage</code>
                            </div>
                        </div>

                        <div class="bono-arm-api-route-list">
                            <strong><?php esc_html_e('Returned fields', 'bono-arm-api'); ?></strong>
                            <code>id</code>
                            <code>arm_log_id</code>
                            <code>username</code>
                            <code>arm_payer_email</code>
                            <code>arm_paid_amount</code>
                            <code>arm_payment_gateway</code>
                            <code>arm_payment_date</code>
                            <code>notes</code>
                            <code>arm_transaction_status</code>
                        </div>
                    </div>
                </section>

                <section class="bono-arm-api-panel" id="specs" data-panel="specs" role="tabpanel" hidden>
                    <div class="bono-arm-api-panel-header">
                        <div>
                            <h2><?php esc_html_e('API Specs', 'bono-arm-api'); ?></h2>
                            <p>
                                <?php esc_html_e('Download the checked-in OpenAPI and Postman artifacts for the current Bono ARM API REST surface, then point them at this site\'s REST root.', 'bono-arm-api'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="bono-arm-api-card bono-arm-api-card-accent">
                        <div class="bono-arm-api-example-grid">
                            <div class="bono-arm-api-example">
                                <strong><?php esc_html_e('OpenAPI 3.1 spec', 'bono-arm-api'); ?></strong>
                                <p><?php esc_html_e('Covers the administrator-protected transactions, member activation, and member deletion endpoints.', 'bono-arm-api'); ?></p>
                                <code id="bono-arm-api-openapi-spec"><?php echo esc_html($openapi_spec_url); ?></code>
                                <div class="bono-arm-api-example-actions">
                                    <a class="button button-primary" href="<?php echo esc_url($openapi_spec_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open spec', 'bono-arm-api'); ?></a>
                                    <button class="button button-secondary" onclick="bonoArmApiCopy('bono-arm-api-openapi-spec'); return false;"><?php esc_html_e('Copy link', 'bono-arm-api'); ?></button>
                                </div>
                            </div>
                            <div class="bono-arm-api-example">
                                <strong><?php esc_html_e('Postman collection', 'bono-arm-api'); ?></strong>
                                <p><?php esc_html_e('Includes ready-to-run requests for listing transactions, activating a member, and deleting a member.', 'bono-arm-api'); ?></p>
                                <code id="bono-arm-api-postman-collection"><?php echo esc_html($postman_collection_url); ?></code>
                                <div class="bono-arm-api-example-actions">
                                    <a class="button button-primary" href="<?php echo esc_url($postman_collection_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open collection', 'bono-arm-api'); ?></a>
                                    <button class="button button-secondary" onclick="bonoArmApiCopy('bono-arm-api-postman-collection'); return false;"><?php esc_html_e('Copy link', 'bono-arm-api'); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bono-arm-api-card">
                        <div class="bono-arm-api-grid bono-arm-api-grid-two">
                            <div class="bono-arm-api-code-card">
                                <strong><?php esc_html_e('REST root / Postman baseUrl', 'bono-arm-api'); ?></strong>
                                <code id="bono-arm-api-rest-root"><?php echo esc_html($rest_root_url); ?></code>
                                <button class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-rest-root'); return false;"><?php esc_html_e('Copy', 'bono-arm-api'); ?></button>
                            </div>
                            <div class="bono-arm-api-code-card">
                                <strong><?php esc_html_e('Authentication', 'bono-arm-api'); ?></strong>
                                <span><?php esc_html_e('Use a WordPress administrator username and an Application Password for both secured routes.', 'bono-arm-api'); ?></span>
                            </div>
                        </div>

                        <p class="bono-arm-api-note">
                            <?php esc_html_e('Import the Postman collection, set', 'bono-arm-api'); ?> <code>baseUrl</code> <?php esc_html_e('to this site\'s REST root, then fill in your WordPress username and Application Password variables. The OpenAPI file uses the same REST root as its server variable.', 'bono-arm-api'); ?>
                        </p>
                    </div>
                </section>

                <section class="bono-arm-api-panel" id="fields" data-panel="fields" role="tabpanel" hidden>
                    <div class="bono-arm-api-panel-header">
                        <div>
                            <h2><?php esc_html_e('Request fields and response shape', 'bono-arm-api'); ?></h2>
                            <p>
                                <?php esc_html_e('Keep external callers aligned with the supported query parameters, pagination behavior, and current JSON structure.', 'bono-arm-api'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="bono-arm-api-card">
                        <div class="bono-arm-api-route-list">
                            <strong><?php esc_html_e('Supported query parameters', 'bono-arm-api'); ?></strong>
                            <code>arm_invoice_id_gt (required integer)</code>
                            <code>arm_plan_id (optional integer)</code>
                            <code>arm_page (optional integer, default 1)</code>
                            <code>arm_perpage (optional integer, default 50)</code>
                        </div>

                        <div class="bono-arm-api-example-grid">
                            <div class="bono-arm-api-example">
                                <strong><?php esc_html_e('Filtered request', 'bono-arm-api'); ?></strong>
                                <code id="bono-arm-api-example-filtered"><?php echo esc_html($example_filtered); ?></code>
                                <button type="button" class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-example-filtered');">
                                    <?php esc_html_e('Copy', 'bono-arm-api'); ?>
                                </button>
                            </div>
                            <div class="bono-arm-api-example">
                                <strong><?php esc_html_e('Disabled-route response', 'bono-arm-api'); ?></strong>
                                <pre>{
  "status": 0,
  "message": "API route not enabled, check your settings",
  "response": {
    "result": []
  }
}</pre>
                            </div>
                        </div>
                    </div>

                    <div class="bono-arm-api-card">
                        <div class="bono-arm-api-panel-copy">
                            <h3><?php esc_html_e('Success response', 'bono-arm-api'); ?></h3>
                            <p>
                                <?php esc_html_e('Payment dates are normalized to ISO 8601, null values are converted to empty strings, and pagination metadata is returned alongside the payment list.', 'bono-arm-api'); ?>
                            </p>
                        </div>
                        <pre>{
  "status": 1,
  "message": "Successfully response result.",
  "response": {
    "result": {
      "payments": [
        {
          "id": 123,
          "arm_log_id": 4567,
          "username": "john_doe",
          "arm_payer_email": "john@example.com",
          "arm_paid_amount": "USD 49.00",
          "arm_payment_gateway": "stripe",
          "arm_payment_date": "2025-05-01T10:20:30+00:00",
          "notes": "",
          "arm_transaction_status": "success"
        }
      ],
      "pagination": {
        "page": 2,
        "per_page": 25,
        "total_count": 340,
        "total_pages": 14
      }
    }
  }
}</pre>
                    </div>

                    <div class="bono-arm-api-card">
                        <div class="bono-arm-api-panel-copy">
                            <h3><?php esc_html_e('Member activation response', 'bono-arm-api'); ?></h3>
                            <p>
                                <?php esc_html_e('The activation endpoint mirrors ARMember’s admin activation flow by setting the user to active, clearing any activation key, and optionally sending the manual activation email.', 'bono-arm-api'); ?>
                            </p>
                        </div>
                        <pre>{
  "status": 1,
  "message": "Member activated successfully.",
  "response": {
    "result": {
      "user_id": 123,
      "primary_status": 1,
      "secondary_status": 0,
      "email_sent": true
    }
  }
}</pre>
                    </div>
                </section>

                <section class="bono-arm-api-panel" id="passwords" data-panel="passwords" role="tabpanel" hidden>
                    <div class="bono-arm-api-panel-header">
                        <div>
                            <h2><?php esc_html_e('How to set up an Application Password', 'bono-arm-api'); ?></h2>
                            <p>
                                <?php esc_html_e('Use WordPress Application Passwords for administrator-authenticated requests to the custom ARMember transactions API.', 'bono-arm-api'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="bono-arm-api-card">
                        <ol class="bono-arm-api-steps">
                            <li><?php esc_html_e('Log in to your WordPress Admin Dashboard.', 'bono-arm-api'); ?></li>
                            <li><?php esc_html_e('Go to Users -> Profile.', 'bono-arm-api'); ?></li>
                            <li><?php esc_html_e('Scroll down to the Application Passwords section.', 'bono-arm-api'); ?></li>
                            <li><?php esc_html_e('Enter a name like ARMember API Access and click Add New Application Password.', 'bono-arm-api'); ?></li>
                            <li><?php esc_html_e('Copy the generated password.', 'bono-arm-api'); ?></li>
                            <li><?php esc_html_e('Use it with your WordPress username in Basic Auth requests.', 'bono-arm-api'); ?></li>
                        </ol>
                        <p class="bono-arm-api-note">
                            <?php esc_html_e('Your site should use HTTPS for Application Passwords. Store the generated password once, because WordPress will not show it again.', 'bono-arm-api'); ?>
                        </p>
                    </div>
                </section>

                <div class="bono-arm-api-footer">
                    <?php submit_button(__('Save settings', 'bono-arm-api'), 'primary', 'submit', false); ?>
                </div>
            </form>
        </div>

        <style>
            .bono-arm-api-admin {
                max-width: 1120px;
                margin-top: 18px;
            }

            .bono-arm-api-hero {
                margin: 0 0 16px;
                border: 1px solid #c8ccd0;
                background: #f6f7f7;
                display: block;
                max-width: 750px;
                width: fit-content;
            }

            .bono-arm-api-hero-image {
                display: block;
                width: min(100%, 750px);
                height: auto;
            }

            .bono-arm-api-headline {
                margin: 8px 0 20px;
            }

            .bono-arm-api-headline h1 {
                margin: 0 0 8px;
                font-size: 42px;
                line-height: 1.1;
                color: #0f172a;
                font-weight: 400;
            }

            .bono-arm-api-intro,
            .bono-arm-api-panel-header p,
            .bono-arm-api-panel-copy p,
            .bono-arm-api-note,
            .bono-arm-api-switch-row p {
                margin: 0;
                color: #475569;
                font-size: 14px;
                line-height: 1.65;
            }

            .bono-arm-api-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin: 16px 0 10px;
            }

            .bono-arm-api-meta a,
            .bono-arm-api-meta span {
                display: inline-flex;
                align-items: center;
                min-height: 36px;
                padding: 0 14px;
                background: #f6f7f7;
                border: 1px solid #c3c4c7;
                color: #0f172a;
                text-decoration: none;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
            }

            .bono-arm-api-meta a:hover {
                border-color: #2271b1;
                color: #2271b1;
            }

            .bono-arm-api-intro {
                margin-bottom: 20px;
                max-width: 76ch;
            }

            .bono-arm-api-intro-secondary {
                margin-top: -8px;
            }

            .bono-arm-api-tabs {
                margin: 24px 0 0;
            }

            .bono-arm-api-tabs .bono-arm-api-tab {
                display: inline-block;
                float: none;
            }

            .bono-arm-api-tabs .bono-arm-api-tab:focus {
                box-shadow: 0 0 0 1px #2271b1;
            }

            .bono-arm-api-shell {
                display: grid;
                gap: 18px;
                padding-top: 20px;
            }

            .bono-arm-api-panel {
                display: grid;
                gap: 18px;
            }

            .bono-arm-api-panel[hidden] {
                display: none;
            }

            .bono-arm-api-panel-header h2,
            .bono-arm-api-panel-copy h3,
            .bono-arm-api-switch-row h3 {
                margin: 0 0 8px;
                color: #0f172a;
            }

            .bono-arm-api-card {
                padding: 22px;
                border: 1px solid #c3c4c7;
                background: #ffffff;
            }

            .bono-arm-api-card-accent {
                border-left: 4px solid #72aee6;
                background: #f6f7f7;
            }

            .bono-arm-api-switch-row {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 18px;
                margin-bottom: 18px;
            }

            .bono-arm-api-toggle {
                display: inline-flex;
                gap: 10px;
                align-items: center;
                background: #ffffff;
                border: 1px solid #c3c4c7;
                padding: 12px 14px;
                font-weight: 600;
                color: #0f172a;
            }

            .bono-arm-api-grid {
                display: grid;
                gap: 14px;
            }

            .bono-arm-api-grid-two,
            .bono-arm-api-example-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .bono-arm-api-code-card,
            .bono-arm-api-example {
                display: grid;
                gap: 8px;
                padding: 14px;
                border: 1px solid #dcdcde;
                background: #ffffff;
            }

            .bono-arm-api-example .button {
                width: fit-content;
            }

            .bono-arm-api-example-actions {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }

            .bono-arm-api-route-list {
                display: grid;
                gap: 8px;
                margin: 18px 0;
            }

            .bono-arm-api-footer {
                display: flex;
                justify-content: flex-start;
            }

            .bono-arm-api-steps {
                margin: 0;
                padding-left: 18px;
                color: #1e293b;
            }

            .bono-arm-api-steps li + li {
                margin-top: 8px;
            }

            code,
            pre {
                background: #f1f1f1;
                border-radius: 4px;
            }

            code {
                padding: 2px 6px;
            }

            pre {
                padding: 12px;
                overflow-x: auto;
                margin: 0;
            }

            @media (max-width: 960px) {
                .bono-arm-api-grid-two,
                .bono-arm-api-example-grid,
                .bono-arm-api-switch-row {
                    grid-template-columns: 1fr;
                    display: grid;
                }

                .bono-arm-api-switch-row {
                    justify-content: stretch;
                }
            }
        </style>
        <script>
        function bonoArmApiCopy(elementId) {
            const source = document.getElementById(elementId);

            if (!source) {
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(source.textContent);
                return;
            }

            const temp = document.createElement('textarea');
            temp.value = source.textContent;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
        }

        document.addEventListener('DOMContentLoaded', function () {
            const tabs = document.querySelectorAll('.bono-arm-api-tab');
            const panels = document.querySelectorAll('.bono-arm-api-panel');

            function activateTab(targetPanel, updateHash) {
                let hasMatch = false;

                tabs.forEach(function (item) {
                    const isTarget = item.getAttribute('data-panel') === targetPanel;
                    item.classList.toggle('nav-tab-active', isTarget);
                    item.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                    hasMatch = hasMatch || isTarget;
                });

                panels.forEach(function (panel) {
                    const isTarget = panel.getAttribute('data-panel') === targetPanel;
                    panel.classList.toggle('is-active', isTarget);
                    panel.hidden = !isTarget;
                });

                if (hasMatch && updateHash) {
                    window.location.hash = targetPanel;
                }
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function (event) {
                    event.preventDefault();
                    activateTab(tab.getAttribute('data-panel'), true);
                });
            });

            const initialPanel = window.location.hash ? window.location.hash.replace('#', '') : 'api';
            activateTab(initialPanel, false);

            window.addEventListener('hashchange', function () {
                const hashPanel = window.location.hash ? window.location.hash.replace('#', '') : 'api';
                activateTab(hashPanel, false);
            });
        });
        </script>
    </div>
    <?php
}

/**
 * Register the plugin REST routes.
 */
function bono_arm_api_register_routes() {
    register_rest_route(
        BONO_ARM_API_NAMESPACE,
        '/arm_payments_log',
        array(
            'methods' => 'GET',
            'callback' => 'bono_get_arm_payments_log',
            'permission_callback' => 'bono_arm_api_current_user_is_administrator',
            'args' => array(
                'arm_plan_id' => array(
                    'type' => 'integer',
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ),
                'arm_invoice_id_gt' => array(
                    'type' => 'integer',
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ),
                'arm_page' => array(
                    'type' => 'integer',
                    'required' => false,
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ),
                'arm_perpage' => array(
                    'type' => 'integer',
                    'required' => false,
                    'default' => 50,
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );

    register_rest_route(
        BONO_ARM_API_NAMESPACE,
        '/members/(?P<user_id>\d+)/activate',
        array(
            'methods' => 'POST',
            'callback' => 'bono_arm_api_activate_member',
            'permission_callback' => 'bono_arm_api_current_user_is_administrator',
            'args' => array(
                'user_id' => array(
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ),
                'send_email' => array(
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
            ),
        )
    );

    register_rest_route(
        BONO_ARM_API_NAMESPACE,
        '/members/(?P<user_id>\d+)/delete',
        array(
            'methods' => 'POST',
            'callback' => 'bono_arm_api_delete_member',
            'permission_callback' => 'bono_arm_api_current_user_is_administrator',
            'args' => array(
                'user_id' => array(
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );
}

/**
 * Return payment log data for the protected ARMember endpoint.
 *
 * @param WP_REST_Request $request Current request.
 * @return WP_REST_Response
 */
function bono_get_arm_payments_log($request) {
    global $wpdb;

    if (!bono_arm_api_is_transactions_enabled()) {
        return bono_arm_api_rest_response(
            0,
            __('API route not enabled, check your settings', 'bono-arm-api')
        );
    }

    $arm_plan_id = $request->get_param('arm_plan_id');
    $min_invoice_id = $request->get_param('arm_invoice_id_gt');
    $page = max(1, (int) $request->get_param('arm_page'));
    $per_page = min(BONO_ARM_API_MAX_PER_PAGE, max(1, (int) $request->get_param('arm_perpage')));

    if (!$min_invoice_id) {
        return bono_arm_api_rest_response(
            0,
            __('Missing parameter(s): arm_invoice_id_gt', 'bono-arm-api')
        );
    }

    if (!bono_arm_api_armember_tables_exist()) {
        return bono_arm_api_rest_response(
            0,
            __('ARMember payment tables are not available. Ensure ARMember is installed and active.', 'bono-arm-api')
        );
    }

    $tables = bono_arm_api_get_armember_tables();

    $offset = ($page - 1) * $per_page;
    $where = $wpdb->prepare(
        "WHERE a.arm_transaction_status = 'success' AND a.arm_invoice_id > %d",
        $min_invoice_id
    );

    if ($arm_plan_id) {
        $where .= $wpdb->prepare(' AND a.arm_plan_id = %d', $arm_plan_id);
    }

    $total_count = $wpdb->get_var(
        "
        SELECT COUNT(*)
        FROM {$tables['payment_log']} AS a
        JOIN {$tables['members']} AS b ON a.arm_user_id = b.arm_user_id
        $where
    "
    );

    $query = $wpdb->prepare(
        "
        SELECT
            a.arm_user_id AS id,
            a.arm_invoice_id AS arm_log_id,
            b.arm_user_login AS username,
            a.arm_payer_email AS arm_payer_email,
            CONCAT(a.arm_currency, ' ', a.arm_amount) AS arm_paid_amount,
            a.arm_payment_gateway AS arm_payment_gateway,
            a.arm_payment_date AS arm_payment_date,
            IF(a.arm_extra_vars LIKE '%manual_by%',
                SUBSTRING_INDEX(SUBSTRING_INDEX(a.arm_extra_vars, 's:13:\"', -1), '\";}', 1),
                '') AS notes,
            a.arm_transaction_status AS arm_transaction_status
        FROM
            {$tables['payment_log']} AS a
        JOIN
            {$tables['members']} AS b ON a.arm_user_id = b.arm_user_id
        $where
        ORDER BY a.arm_invoice_id DESC
        LIMIT %d OFFSET %d
    ",
        $per_page,
        $offset
    );

    $results = $wpdb->get_results($query, ARRAY_A);

    if (!empty($wpdb->last_error)) {
        return bono_arm_api_rest_response(
            0,
            __('Unable to load ARMember payment records right now.', 'bono-arm-api')
        );
    }

    if (!is_array($results)) {
        $results = array();
    }

    foreach ($results as &$row) {
        foreach ($row as $key => $value) {
            $row[$key] = is_null($value) ? '' : $value;
        }

        if (!empty($row['arm_payment_date'])) {
            $timestamp = strtotime($row['arm_payment_date']);
            $row['arm_payment_date'] = false !== $timestamp ? gmdate('c', $timestamp) : '';
        }
    }
    unset($row);

    return bono_arm_api_rest_response(
        1,
        __('Successfully response result.', 'bono-arm-api'),
        array(
            'payments' => $results,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total_count' => (int) $total_count,
                'total_pages' => (int) ceil((int) $total_count / $per_page),
            ),
        )
    );
}

/**
 * Activate an ARMember user through the protected REST endpoint.
 *
 * @param WP_REST_Request $request Current request.
 * @return WP_REST_Response
 */
function bono_arm_api_activate_member($request) {
    $user_id = absint($request->get_param('user_id'));
    $send_email = (bool) rest_sanitize_boolean($request->get_param('send_email'));

    if (!bono_arm_api_is_member_activation_enabled()) {
        return bono_arm_api_rest_response(
            0,
            __('API route not enabled, check your settings', 'bono-arm-api')
        );
    }

    if (!$user_id) {
        return bono_arm_api_rest_response(
            0,
            __('Missing or invalid parameter: user_id', 'bono-arm-api')
        );
    }

    if (!bono_arm_api_armember_tables_exist()) {
        return bono_arm_api_rest_response(
            0,
            __('ARMember payment tables are not available. Ensure ARMember is installed and active.', 'bono-arm-api')
        );
    }

    $user = get_user_by('ID', $user_id);

    if (!$user instanceof WP_User) {
        return bono_arm_api_rest_response(
            0,
            __('User not found.', 'bono-arm-api')
        );
    }

    if (!function_exists('arm_set_member_status')) {
        return bono_arm_api_rest_response(
            0,
            __('ARMember member status functions are not available. Ensure ARMember is fully loaded.', 'bono-arm-api')
        );
    }

    arm_set_member_status($user_id, 1);

    $email_sent = false;

    if ($send_email) {
        global $arm_email_settings, $arm_global_settings;

        if (
            isset($arm_global_settings, $arm_email_settings->templates->on_menual_activation) &&
            is_object($arm_global_settings) &&
            method_exists($arm_global_settings, 'arm_mailer')
        ) {
            $arm_global_settings->arm_mailer($arm_email_settings->templates->on_menual_activation, $user_id);
            $email_sent = true;
        }
    }

    return bono_arm_api_rest_response(
        1,
        __('Member activated successfully.', 'bono-arm-api'),
        array(
            'user_id' => $user_id,
            'primary_status' => (int) get_user_meta($user_id, 'arm_primary_status', true),
            'secondary_status' => (int) get_user_meta($user_id, 'arm_secondary_status', true),
            'email_sent' => $email_sent,
        )
    );
}

/**
 * Delete an ARMember user through the protected REST endpoint.
 *
 * @param WP_REST_Request $request Current request.
 * @return WP_REST_Response
 */
function bono_arm_api_delete_member($request) {
    $user_id = absint($request->get_param('user_id'));

    if (!bono_arm_api_is_member_delete_enabled()) {
        return bono_arm_api_rest_response(
            0,
            __('API route not enabled, check your settings', 'bono-arm-api')
        );
    }

    if (!$user_id) {
        return bono_arm_api_rest_response(
            0,
            __('Missing or invalid parameter: user_id', 'bono-arm-api')
        );
    }

    if (is_multisite()) {
        return bono_arm_api_rest_response(
            0,
            __('Deleting members through this endpoint is not supported on multisite installs.', 'bono-arm-api')
        );
    }

    if (!bono_arm_api_armember_tables_exist()) {
        return bono_arm_api_rest_response(
            0,
            __('ARMember payment tables are not available. Ensure ARMember is installed and active.', 'bono-arm-api')
        );
    }

    $user = get_user_by('ID', $user_id);

    if (!$user instanceof WP_User) {
        return bono_arm_api_rest_response(
            0,
            __('User not found.', 'bono-arm-api')
        );
    }

    $arm_members_manager = bono_arm_api_get_armember_members_manager();
    $can_call_armember_delete = bono_arm_api_armember_members_manager_can_delete($arm_members_manager);
    $hooks_active = bono_arm_api_armember_delete_hooks_are_active($arm_members_manager);

    if (!$hooks_active && !$can_call_armember_delete) {
        return bono_arm_api_rest_response(
            0,
            __('ARMember delete lifecycle is not available. Ensure ARMember is fully loaded before deleting members.', 'bono-arm-api')
        );
    }

    if (!function_exists('wp_delete_user') && file_exists(ABSPATH . 'wp-admin/includes/user.php')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }

    if (!function_exists('wp_delete_user')) {
        return bono_arm_api_rest_response(
            0,
            __('WordPress user deletion functions are not available.', 'bono-arm-api')
        );
    }

    $deleted_user_login = $user->user_login;
    $deleted_user_email = $user->user_email;
    $cleanup_mode = $hooks_active ? 'automatic_hooks' : 'manual_fallback';

    if (!$hooks_active && $can_call_armember_delete) {
        $arm_members_manager->arm_before_delete_user_action($user_id, 1);
    }

    $deleted = wp_delete_user($user_id, 1);

    if (!$deleted) {
        return bono_arm_api_rest_response(
            0,
            __('Member deletion failed.', 'bono-arm-api')
        );
    }

    if (!$hooks_active && $can_call_armember_delete) {
        $arm_members_manager->arm_after_deleted_user_action($user_id, 1);
    }

    return bono_arm_api_rest_response(
        1,
        __('Member deleted successfully.', 'bono-arm-api'),
        array(
            'user_id' => $user_id,
            'user_login' => $deleted_user_login,
            'user_email' => $deleted_user_email,
            'cleanup_mode' => $cleanup_mode,
        )
    );
}
