<?php
/*
Plugin Name: Bono API for ARMember
Plugin URI: https://github.com/renatobo/bono_arm_api
Description: Admin-only REST API access to ARMember payment logs with filtering and pagination.
Version: 1.0.8
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

define('BONO_ARM_API_VERSION', '1.0.8');
define('BONO_ARM_API_NAMESPACE', 'bono_armember/v1');
define('BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS', 'bono_arm_api_enable_transactions');
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
 * Render the plugin settings page.
 */
function bono_arm_api_render_settings_page() {
    $api_enabled = bono_arm_api_is_transactions_enabled();
    $project_url = 'https://github.com/renatobo/bono_arm_api';
    $author_url = 'https://github.com/renatobo';
    $git_updater_url = 'https://github.com/afragen/git-updater';
    $banner_url = plugins_url('assets/bono-arm-api-settings-banner.svg', __FILE__);
    $endpoint_url = rest_url(BONO_ARM_API_NAMESPACE . '/arm_payments_log');
    $example_basic = $endpoint_url . '?arm_invoice_id_gt=1450';
    $example_filtered = $endpoint_url . '?arm_invoice_id_gt=1450&arm_plan_id=2&arm_page=2&arm_perpage=25';
    $example_curl = 'curl -u your_username:your_app_password "' . $example_basic . '"';
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
                <a href="<?php echo esc_url($author_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Renato Bonomini on GitHub', 'bono-arm-api'); ?>
                </a>
                <a href="<?php echo esc_url($git_updater_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Updates via Git Updater', 'bono-arm-api'); ?>
                </a>
            </div>

            <div class="bono-arm-api-headline">
                <h1><?php esc_html_e('Bono ARM API Settings', 'bono-arm-api'); ?></h1>
                <p class="bono-arm-api-intro">
                    <?php esc_html_e('Control the availability of the custom ARMember transactions API surface, review the supported request parameters, and share administrator-authenticated access with external systems.', 'bono-arm-api'); ?>
                </p>
                <p class="bono-arm-api-intro bono-arm-api-intro-secondary">
                    <?php esc_html_e('This plugin is designed for WordPress integrations that need filtered ARMember payment logs without exposing the endpoint publicly.', 'bono-arm-api'); ?>
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
                                    <?php checked(true, $api_enabled, true); ?>
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
                            <code>GET <?php echo esc_html($endpoint_url); ?></code>
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
