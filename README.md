# Bono API for ARMember

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![Tested up to](https://img.shields.io/badge/Tested%20up%20to-6.9.4-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![Release](https://img.shields.io/github/v/release/renatobo/bono_arm_api?label=release)](https://github.com/renatobo/bono_arm_api/releases)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

WordPress plugin that exposes a protected REST API endpoint for ARMember payment logs, with pagination and filtering for external integrations.

Current version: `1.0.9`

## Quick start

1. Copy this plugin to your WordPress plugins folder.
2. Activate **Bono API for ARMember**.
3. Go to **Settings -> Bono ARM API**.
4. Enable **List of Transactions**.
5. Create a WordPress **Application Password** for an administrator user.
6. Call the endpoint:

```bash
curl -u your_username:your_app_password \
  "https://your-site.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450"
```

## Features

- Dedicated endpoint: `GET /wp-json/bono_armember/v1/arm_payments_log`
- Filters by invoice threshold and ARMember plan
- Pagination support for large transaction sets
- Returns only successful ARMember transactions
- Access restricted to administrator users
- Endpoint can be enabled/disabled from plugin settings
- Compatible with WordPress Application Passwords

## Requirements

- WordPress `5.0+`
- ARMember plugin installed and active
- HTTPS-enabled site (recommended for secure API auth)

If ARMember is unavailable, the endpoint returns `status: 0` with a dependency message instead of querying missing tables.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins** in wp-admin.
3. Open **Settings -> Bono ARM API**.
4. Enable the **List of Transactions** option.
5. Install **Git Updater** if you want this site to track GitHub releases, which is the primary distribution channel for this plugin.

## Packaging

Build an installable plugin zip from the repo root:

```bash
./build.sh
```

That creates a Git Updater-compatible release asset like `bono_arm_api-x.y.z.zip`, containing the installable plugin folder `bono_arm_api/`.

## Releases

To prepare a tagged release from the command line:

```bash
./release.sh x.y.z
```

That script:

- updates the plugin header version in `bono-arm-api.php`
- updates `BONO_ARM_API_VERSION`
- updates the `Stable tag` in `readme.txt`
- commits the version bump
- creates and pushes the git tag `vx.y.z`
- verifies that all version references match

Pushing the tag triggers GitHub Actions, which runs `./build.sh`, creates or updates the GitHub Release, and uploads the generated zip asset automatically.

## Authentication

This endpoint requires WordPress authentication and checks for the `administrator` role.

Recommended method: **Application Passwords**.

1. Go to **Users -> Profile**.
2. In **Application Passwords**, create a new password.
3. Use `username:application_password` in Basic Auth.

Example:

```bash
curl -u your_username:your_app_password \
  "https://your-site.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450&arm_page=1&arm_perpage=50"
```

## API reference

### Endpoint

`GET /wp-json/bono_armember/v1/arm_payments_log`

### Query parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `arm_invoice_id_gt` | integer | Yes | Return records where invoice ID is greater than this value |
| `arm_plan_id` | integer | No | Filter by ARMember plan ID |
| `arm_page` | integer | No | Page number (default: `1`) |
| `arm_perpage` | integer | No | Items per page (default: `50`, maximum: `100`) |

### Example request

```text
https://your-site.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450&arm_plan_id=2&arm_page=2&arm_perpage=25
```

### Success response shape

```json
{
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
}
```

### Common error responses

- Endpoint disabled in settings:

```json
{
  "status": 0,
  "message": "API route not enabled, check your settings",
  "response": {
    "result": []
  }
}
```

- Missing required parameter:

```json
{
  "status": 0,
  "message": "Missing parameter(s): arm_invoice_id_gt",
  "response": {
    "result": []
  }
}
```

- ARMember unavailable:

```json
{
  "status": 0,
  "message": "ARMember payment tables are not available. Ensure ARMember is installed and active.",
  "response": {
    "result": []
  }
}
```

## Automatic updates

This plugin is compatible with [Git Updater](https://github.com/afragen/git-updater).

The intended distribution model is dual-channel:

- GitHub releases are the primary install and update path for sites running Git Updater.
- WordPress.org is the secondary channel when that listing is available.

Install Git Updater to receive update notifications and one-click updates from this repository.

## Release process

- `readme.txt` keeps the `Stable tag` version
- GitHub Actions can tag releases from `main`
- Tagged versions build a plugin zip release asset

## Related repositories

- [WebHookARM](https://github.com/renatobo/WebHookARM)
- [TelegrARM](https://github.com/renatobo/TelegrARM)

## License

Licensed under [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
