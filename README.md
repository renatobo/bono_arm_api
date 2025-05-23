# bono_arm_api
Extended API endpoints for ARMember (WP Plugin)

# ARMember Extended API Services

A WordPress plugin that exposes extended REST API endpoints for ARMember transactions, including pagination, filtering, and admin-controlled access.

---

## Automatic Updates

This plugin supports automatic updates via the [GitHub Updater](https://github.com/afragen/github-updater) plugin.  
[Install GitHub Updater](https://github.com/afragen/github-updater) to receive update notifications and one-click updates directly from this repository.

---

## Features

- Secure REST API endpoint for ARMember payment logs
- Pagination, filtering, and sorting support
- Access control limited to Administrators
- Toggle API availability via WordPress settings
- Compatible with Application Password authentication

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin from the WordPress admin.
3. Go to **Settings → Bono ARM API** and enable "List of Transactions".
4. *(Optional but recommended)* Install and activate [GitHub Updater](https://github.com/afragen/github-updater) to enable automatic plugin updates.

## Usage

### API Endpoint

```
GET /wp-json/bono_armember/v1/arm_payments_log
```

#### Required Parameter

- `arm_invoice_id_gt`: integer – return only transactions with invoice ID greater than this value

#### Optional Parameters

- `arm_plan_id`: integer – filter by plan ID
- `arm_page`: integer – results page (default: 1)
- `arm_perpage`: integer – results per page (default: 50)

#### Example

Basic:
```
https://yourwebsite.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450
```

With filters and pagination:
```
https://yourwebsite.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450&arm_plan_id=2&arm_page=2&arm_perpage=25
```

## Authentication

This API requires authentication via WordPress Application Passwords.

### How to Set Up

1. Go to **Users → Profile** in your WordPress admin dashboard.
2. Scroll to **Application Passwords** and create a new one.
3. Use your WordPress username and the generated password in your API request.

### Example `curl` Call

```bash
curl -u your_username:your_app_password "https://yourwebsite.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450"
```

## FAQ

**Who can access the API?**  
Only WordPress administrators can access the API endpoint.

**How do I enable or disable the API?**  
Go to **Settings → Bono ARM API** and use the checkbox to enable or disable the List of Transactions endpoint.

**What authentication method is supported?**  
WordPress Application Passwords.

## License

This plugin is licensed under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
