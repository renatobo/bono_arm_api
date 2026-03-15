<?php
/*
Plugin Name: ARMember Extended API services
Plugin URI: https://github.com/renatobo/bono_arm_api
Description: Exposes extended API endpoints for ARMember transactions including pagination, filtering, and admin-controlled access.
Version: 1.0.4
Author: Renato Bonomini
Author URI: https://github.com/renatobo
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

GitHub Plugin URI: https://github.com/renatobo/bono_arm_api
Primary Branch: main
Release Asset: true
*/

if (!defined('ABSPATH')) {
    exit;
}

define('BONO_ARM_API_VERSION', '1.0.4');
define('BONO_ARM_API_NAMESPACE', 'bono_armember/v1');
define('BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS', 'bono_arm_api_enable_transactions');
define('BONO_ARM_API_SETTINGS_PAGE', 'bono-arm-api-settings');
define('BONO_ARM_API_MAX_PER_PAGE', 100);

add_action('admin_menu', 'bono_arm_api_add_settings_page');
add_action('admin_init', 'bono_arm_api_register_settings');
add_action('rest_api_init', 'bono_arm_api_register_routes');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bono_arm_api_add_plugin_action_links');

/**
 * Add the plugin settings page.
 */
function bono_arm_api_add_settings_page() {
    add_options_page(
        'Bono ARM API Settings',
        'Bono ARM API',
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
 * Render the plugin settings page.
 */
function bono_arm_api_render_settings_page() {
    $site_url = untrailingslashit(get_site_url());
    $api_enabled = bono_arm_api_is_transactions_enabled();
    $project_url = 'https://github.com/renatobo/bono_arm_api';
    $author_url = 'https://github.com/renatobo';
    $git_updater_url = 'https://github.com/afragen/git-updater';
    $banner_url = plugins_url('assets/bono-arm-api-settings-banner.svg', __FILE__);
    $endpoint_url = $site_url . '/wp-json/' . BONO_ARM_API_NAMESPACE . '/arm_payments_log';
    $example_basic = $endpoint_url . '?arm_invoice_id_gt=1450';
    $example_filtered = $endpoint_url . '?arm_invoice_id_gt=1450&arm_plan_id=2&arm_page=2&arm_perpage=25';
    $example_curl = 'curl -u your_username:your_app_password "' . $example_basic . '"';
    ?>
    <div class="wrap">
        <div class="bono-arm-api-admin">
            <div class="bono-arm-api-hero">
                <img
                    src="<?php echo esc_url($banner_url); ?>"
                    alt="Bono ARM API settings banner"
                    class="bono-arm-api-hero-image"
                />
            </div>

            <div class="bono-arm-api-meta">
                <a href="<?php echo esc_url($project_url); ?>" target="_blank" rel="noopener noreferrer">
                    Plugin Repository
                </a>
                <span>Version <?php echo esc_html(BONO_ARM_API_VERSION); ?></span>
                <a href="<?php echo esc_url($author_url); ?>" target="_blank" rel="noopener noreferrer">
                    Renato Bonomini on GitHub
                </a>
                <a href="<?php echo esc_url($git_updater_url); ?>" target="_blank" rel="noopener noreferrer">
                    Updates via Git Updater
                </a>
            </div>

            <div class="bono-arm-api-headline">
                <h1>Bono ARM API Settings</h1>
                <p class="bono-arm-api-intro">
                    Control the availability of the custom ARMember transactions API surface, review the supported
                    request parameters, and share administrator-authenticated access with external systems.
                </p>
                <p class="bono-arm-api-intro bono-arm-api-intro-secondary">
                    This plugin is designed for WordPress integrations that need filtered ARMember payment logs without
                    exposing the endpoint publicly.
                </p>
            </div>

            <?php settings_errors(); ?>

            <nav class="nav-tab-wrapper bono-arm-api-tabs" role="tablist" aria-label="Bono ARM API sections">
                <a href="#api" class="nav-tab bono-arm-api-tab nav-tab-active" role="tab" aria-selected="true" data-panel="api">
                    Transactions API
                </a>
                <a href="#fields" class="nav-tab bono-arm-api-tab" role="tab" aria-selected="false" data-panel="fields">
                    Request fields
                </a>
                <a href="#passwords" class="nav-tab bono-arm-api-tab" role="tab" aria-selected="false" data-panel="passwords">
                    Application Passwords
                </a>
            </nav>

            <form method="post" action="options.php" class="bono-arm-api-shell">
                <?php settings_fields('bono_arm_api_settings_group'); ?>
                <?php do_settings_sections('bono_arm_api_settings_group'); ?>

                <section class="bono-arm-api-panel is-active" id="api" data-panel="api" role="tabpanel">
                    <div class="bono-arm-api-panel-header">
                        <div>
                            <h2>Transactions endpoint</h2>
                            <p>
                                Gate the custom <code><?php echo esc_html(BONO_ARM_API_NAMESPACE); ?></code> REST
                                endpoint without changing its authentication requirements or response shape.
                            </p>
                        </div>
                    </div>

                    <div class="bono-arm-api-card bono-arm-api-card-accent">
                        <div class="bono-arm-api-switch-row">
                            <div>
                                <h3>List of Transactions</h3>
                                <p>
                                    Enable or disable the protected REST endpoint under
                                    <code>/wp-json/<?php echo esc_html(BONO_ARM_API_NAMESPACE); ?>/arm_payments_log</code>.
                                </p>
                            </div>
                            <label class="bono-arm-api-toggle">
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS); ?>"
                                    value="1"
                                    <?php checked(true, $api_enabled, true); ?>
                                />
                                <span>Enable transactions API</span>
                            </label>
                        </div>

                        <div class="bono-arm-api-grid bono-arm-api-grid-two">
                            <div class="bono-arm-api-code-card">
                                <strong>Namespace</strong>
                                <code>/wp-json/<?php echo esc_html(BONO_ARM_API_NAMESPACE); ?></code>
                            </div>
                            <div class="bono-arm-api-code-card">
                                <strong>Authentication</strong>
                                <span>Administrator role required with WordPress credentials or Application Passwords.</span>
                            </div>
                        </div>

                        <div class="bono-arm-api-route-list">
                            <strong>Route</strong>
                            <code>GET <?php echo esc_html($endpoint_url); ?></code>
                        </div>

                        <div class="bono-arm-api-example-grid">
                            <div class="bono-arm-api-example">
                                <strong>Basic request</strong>
                                <code id="bono-arm-api-example-basic"><?php echo esc_html($example_basic); ?></code>
                                <button type="button" class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-example-basic');">
                                    Copy
                                </button>
                            </div>
                            <div class="bono-arm-api-example">
                                <strong>Authenticated curl example</strong>
                                <code id="bono-arm-api-example-curl"><?php echo esc_html($example_curl); ?></code>
                                <button type="button" class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-example-curl');">
                                    Copy
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="bono-arm-api-card">
                        <div class="bono-arm-api-panel-copy">
                            <h3>Behavior</h3>
                            <p>
                                The endpoint returns only successful ARMember transactions, supports invoice-threshold
                                filtering plus pagination, and keeps the existing JSON envelope used by external callers.
                            </p>
                        </div>

                        <div class="bono-arm-api-grid bono-arm-api-grid-two">
                            <div class="bono-arm-api-code-card">
                                <strong>Required query parameter</strong>
                                <code>arm_invoice_id_gt</code>
                                <span>Fetch only records with an invoice ID greater than the value provided.</span>
                            </div>
                            <div class="bono-arm-api-code-card">
                                <strong>Optional filters</strong>
                                <code>arm_plan_id</code>
                                <code>arm_page</code>
                                <code>arm_perpage</code>
                            </div>
                        </div>

                        <div class="bono-arm-api-route-list">
                            <strong>Returned fields</strong>
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
                            <h2>Request fields and response shape</h2>
                            <p>
                                Keep external callers aligned with the supported query parameters, pagination behavior,
                                and current JSON structure.
                            </p>
                        </div>
                    </div>

                    <div class="bono-arm-api-card">
                        <div class="bono-arm-api-route-list">
                            <strong>Supported query parameters</strong>
                            <code>arm_invoice_id_gt (required integer)</code>
                            <code>arm_plan_id (optional integer)</code>
                            <code>arm_page (optional integer, default 1)</code>
                            <code>arm_perpage (optional integer, default 50)</code>
                        </div>

                        <div class="bono-arm-api-example-grid">
                            <div class="bono-arm-api-example">
                                <strong>Filtered request</strong>
                                <code id="bono-arm-api-example-filtered"><?php echo esc_html($example_filtered); ?></code>
                                <button type="button" class="button button-secondary button-small" onclick="bonoArmApiCopy('bono-arm-api-example-filtered');">
                                    Copy
                                </button>
                            </div>
                            <div class="bono-arm-api-example">
                                <strong>Disabled-route response</strong>
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
                            <h3>Success response</h3>
                            <p>
                                Payment dates are normalized to ISO 8601, null values are converted to empty strings,
                                and pagination metadata is returned alongside the payment list.
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
                            <h2>How to set up an Application Password</h2>
                            <p>
                                Use WordPress Application Passwords for administrator-authenticated requests to the
                                custom ARMember transactions API.
                            </p>
                        </div>
                    </div>

                    <div class="bono-arm-api-card">
                        <ol class="bono-arm-api-steps">
                            <li>Log in to your WordPress Admin Dashboard.</li>
                            <li>Go to <strong>Users -&gt; Profile</strong>.</li>
                            <li>Scroll down to the <strong>Application Passwords</strong> section.</li>
                            <li>Enter a name like <em>ARMember API Access</em> and click <strong>Add New Application Password</strong>.</li>
                            <li>Copy the generated password.</li>
                            <li>Use it with your WordPress username in Basic Auth requests.</li>
                        </ol>
                        <p class="bono-arm-api-note">
                            Your site should use HTTPS for Application Passwords. Store the generated password once,
                            because WordPress will not show it again.
                        </p>
                    </div>
                </section>

                <div class="bono-arm-api-footer">
                    <?php submit_button('Save settings', 'primary', 'submit', false); ?>
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
        return rest_ensure_response(
            array(
                'status' => 0,
                'message' => 'API route not enabled, check your settings',
                'response' => array(
                    'result' => array(),
                ),
            )
        );
    }

    $arm_plan_id = $request->get_param('arm_plan_id');
    $min_invoice_id = $request->get_param('arm_invoice_id_gt');
    $page = max(1, (int) $request->get_param('arm_page'));
    $per_page = min(BONO_ARM_API_MAX_PER_PAGE, max(1, (int) $request->get_param('arm_perpage')));

    if (!$min_invoice_id) {
        return rest_ensure_response(
            array(
                'status' => 0,
                'message' => 'Missing parameter(s): arm_invoice_id_gt',
                'response' => array(
                    'result' => array(),
                ),
            )
        );
    }

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
        FROM {$wpdb->prefix}arm_payment_log AS a
        JOIN {$wpdb->prefix}arm_members AS b ON a.arm_user_id = b.arm_user_id
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
            {$wpdb->prefix}arm_payment_log AS a
        JOIN
            {$wpdb->prefix}arm_members AS b ON a.arm_user_id = b.arm_user_id
        $where
        ORDER BY a.arm_invoice_id DESC
        LIMIT %d OFFSET %d
    ",
        $per_page,
        $offset
    );

    $results = $wpdb->get_results($query, ARRAY_A);

    foreach ($results as &$row) {
        foreach ($row as $key => $value) {
            $row[$key] = is_null($value) ? '' : $value;
        }

        $row['arm_payment_date'] = $row['arm_payment_date']
            ? gmdate('c', strtotime($row['arm_payment_date']))
            : '';
    }
    unset($row);

    return rest_ensure_response(
        array(
            'status' => 1,
            'message' => 'Successfully response result.',
            'response' => array(
                'result' => array(
                    'payments' => $results,
                    'pagination' => array(
                        'page' => $page,
                        'per_page' => $per_page,
                        'total_count' => (int) $total_count,
                        'total_pages' => (int) ceil($total_count / $per_page),
                    ),
                ),
            ),
        )
    );
}
