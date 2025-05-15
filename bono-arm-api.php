<?php
/*
Plugin Name: ARMember Extended API services
Plugin URI: https://github.com/renatobo/bono_arm_api
Description: Exposes extended API endpoints for ARMember transactions including pagination, filtering, and admin-controlled access.
Version: 1.0.1
Author: Renato Bonomini
Author URI: https://github.com/renatobo
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

GitHub Plugin URI: https://github.com/renatobo/bono_arm_api
GitHub Branch: main
*/

if (!defined('ABSPATH')) exit;

// Add settings menu
add_action('admin_menu', function () {
    add_options_page(
        'Bono ARM API Settings',
        'Bono ARM API',
        'manage_options',
        'bono-arm-api-settings',
        'bono_arm_api_settings_page'
    );
});

// Register setting
add_action('admin_init', function () {
    register_setting('bono_arm_api_settings_group', 'bono_arm_api_enable_transactions');
});

// Settings page HTML
function bono_arm_api_settings_page() {
    ?>
    <div class="wrap">
        <h1>Bono ARMemeber extended API endpoints Settings</h1>
        <p class="description">
            Control the availability of the custom API endpoints for ARM Member transactions. Toggle below to enable or disable the List of Transactions endpoint.
        </p>

        <div style="background: #eaf6ff; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <strong>Automatic Updates:</strong>
            <br>
            This plugin supports automatic updates via the <a href="https://github.com/afragen/github-updater" target="_blank" rel="noopener noreferrer">GitHub Updater</a> plugin.
            <br>
            <a href="https://github.com/afragen/github-updater" target="_blank" rel="noopener noreferrer">Install GitHub Updater</a> to receive update notifications and one-click updates directly from this repository.
        </div>

        <form method="post" action="options.php">
            <?php settings_fields('bono_arm_api_settings_group'); ?>
            <?php do_settings_sections('bono_arm_api_settings_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        List of Transactions
                        <span class="dashicons dashicons-editor-help" title="Enable this to allow the API endpoint /arm_payments_log to respond. When disabled, the API will return an error message."></span>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="bono_arm_api_enable_transactions" value="1" <?php checked(1, get_option('bono_arm_api_enable_transactions'), true); ?> />
                            Enable the API endpoint for listing transactions
                        </label>
                        <p class="description">
                            When enabled, external systems can query the list of ARM payment transactions via the REST API.
                            <br><br>
                            <strong>API Endpoint:</strong><br>
                            <code>/wp-json/bono_armember/v1/arm_payments_log</code>
                            <br><br>
                            <strong>Required Parameter:</strong><br>
                            <code>arm_invoice_id_gt</code> (integer) — fetch transactions with invoice ID greater than this number
                            <br>
                            <strong>Optional Parameters:</strong><br>
                            <code>arm_plan_id</code> (integer) — filter by plan ID<br>
                            <code>arm_page</code> (integer) — page number for pagination (default is 1)<br>
                            <code>arm_perpage</code> (integer) — number of results per page (default is 50)
                            <br><br>
                            <strong>Examples:</strong><br>
                            Without pagination:<br>
                            <div class="api-example">
                              <code id="example1"><?php echo get_site_url(); ?>/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450</code>
                              <button class="copy-btn" onclick="copyToClipboard('example1')">Copy</button>
                            </div>
                            With pagination and plan filter:<br>
                            <div class="api-example">
                              <code id="example2"><?php echo get_site_url(); ?>/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450&amp;arm_plan_id=2&amp;arm_page=2&amp;arm_perpage=25</code>
                              <button class="copy-btn" onclick="copyToClipboard('example2')">Copy</button>
                            </div>
                            Using curl from the command line:<br>
                            <div class="api-example">
                              <code id="example3">curl -u your_username:your_app_password "<?php echo get_site_url(); ?>/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450"</code>
                              <button class="copy-btn" onclick="copyToClipboard('example3')">Copy</button>
                            </div>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2>How to set up an Application Password</h2>
        <ol>
            <li>Log in to your WordPress Admin Dashboard</li>
            <li>Go to <strong>Users → Profile</strong> (or "Your Profile")</li>
            <li>Scroll down to the <strong>Application Passwords</strong> section</li>
            <li>Enter a name like <em>ARMember API Access</em> and click <strong>Add New Application Password</strong></li>
            <li>Copy the generated password (you won't see it again)</li>
            <li>Use it in API calls with your WordPress username and this password</li>
        </ol>
        <p><strong>Note:</strong> Your website must use HTTPS for Application Passwords to work.</p>

        <style>
            .form-table th {
                display: flex;
                align-items: center;
            }
            .form-table th .dashicons-editor-help {
                margin-left: 5px;
                cursor: help;
                color: #666;
            }
            .form-table th .dashicons-editor-help:hover {
                color: #0073aa;
            }
            ol {
                padding-left: 20px;
            }
            ol li {
                margin-bottom: 5px;
            }
            code {
                background: #f1f1f1;
                padding: 2px 6px;
                border-radius: 4px;
            }
            .copy-btn {
                margin-left: 10px;
                padding: 2px 8px;
                font-size: 11px;
                cursor: pointer;
            }
            .api-example {
                display: flex;
                align-items: center;
                margin-bottom: 8px;
            }
        </style>
        <script>
        function copyToClipboard(elementId) {
            var temp = document.createElement('textarea');
            temp.value = document.getElementById(elementId).textContent;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
        }
        </script>
    </div>
    <?php
}

// Register REST route
add_action('rest_api_init', function () {
    register_rest_route('bono_armember/v1', '/arm_payments_log', [
        'methods' => 'GET',
        'callback' => 'bono_get_arm_payments_log',
        'permission_callback' => function () {
            $user = wp_get_current_user();
            return in_array('administrator', (array) $user->roles);
        },
        'args' => [
            'arm_plan_id' => ['type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint'],
            'arm_invoice_id_gt' => ['type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint'],
            'arm_page' => ['type' => 'integer', 'required' => false, 'default' => 1, 'sanitize_callback' => 'absint'],
            'arm_perpage' => ['type' => 'integer', 'required' => false, 'default' => 50, 'sanitize_callback' => 'absint'],
        ],
    ]);
});

// Callback
function bono_get_arm_payments_log($request) {
    global $wpdb;

    if (!get_option('bono_arm_api_enable_transactions')) {
        return rest_ensure_response([
            "status" => 0,
            "message" => "API route not enabled, check your settings",
            "response" => ["result" => []]
        ]);
    }

    $arm_plan_id = $request->get_param('arm_plan_id');
    $min_invoice_id = $request->get_param('arm_invoice_id_gt');
    $page = max(1, $request->get_param('arm_page'));
    $per_page = max(1, $request->get_param('arm_perpage'));

    if (!$min_invoice_id) {
        return rest_ensure_response([
            "status" => 0,
            "message" => "Missing parameter(s): arm_invoice_id_gt",
            "response" => ["result" => []]
        ]);
    }

    $offset = ($page - 1) * $per_page;
    $where = $wpdb->prepare("WHERE a.arm_transaction_status = 'success' AND a.arm_invoice_id > %d", $min_invoice_id);
    if ($arm_plan_id) {
        $where .= $wpdb->prepare(" AND a.arm_plan_id = %d", $arm_plan_id);
    }

    $total_count = $wpdb->get_var("
        SELECT COUNT(*)
        FROM {$wpdb->prefix}arm_payment_log AS a
        JOIN {$wpdb->prefix}arm_members AS b ON a.arm_user_id = b.arm_user_id
        $where
    ");

    $query = $wpdb->prepare("
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
    ", $per_page, $offset);

    $results = $wpdb->get_results($query, ARRAY_A);
    foreach ($results as &$row) {
        foreach ($row as $key => $value) {
            $row[$key] = is_null($value) ? "" : $value;
        }
        $row['arm_payment_date'] = $row['arm_payment_date'] ? gmdate('c', strtotime($row['arm_payment_date'])) : "";
    }

    return rest_ensure_response([
        "status" => 1,
        "message" => "Successfully response result.",
        "response" => [
            "result" => [
                "payments" => $results,
                "pagination" => [
                    "page" => $page,
                    "per_page" => $per_page,
                    "total_count" => intval($total_count),
                    "total_pages" => ceil($total_count / $per_page),
                ]
            ]
        ]
    ]);
}
