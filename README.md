# Bono API for ARMember

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![Tested up to](https://img.shields.io/badge/Tested%20up%20to-7.0-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![Release](https://img.shields.io/github/v/release/renatobo/bono_arm_api?label=release)](https://github.com/renatobo/bono_arm_api/releases)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

WordPress plugin that exposes protected REST API endpoints for ARMember payment logs, admin-triggered member activation, and guarded member deletion.

Current version: `2.0.1`

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
- Dedicated endpoint: `POST /wp-json/bono_armember/v1/members/{user_id}/activate`
- Dedicated endpoint: `POST /wp-json/bono_armember/v1/members/{user_id}/delete`
- Schema-first v2 endpoints with standard REST errors and HTTP methods
- Cursor-based payment pagination without a count query by default
- Checked-in API specs under `docs/` for OpenAPI 3.1 and Postman
- Filters by invoice threshold and ARMember plan
- Pagination support for large transaction sets
- Optional member activation email dispatch through ARMember
- Guarded member deletion through `wp_delete_user()` with ARMember cleanup lifecycle preservation
- Returns only successful ARMember transactions
- Dedicated least-privilege capabilities for payment reads, activation, and deletion
- Endpoint can be enabled/disabled from plugin settings
- Compatible with WordPress Application Passwords

## Requirements

- WordPress `6.9+` (tested through WordPress `7.0`)
- PHP `7.4+`
- ARMember plugin installed and active
- HTTPS-enabled site (recommended for secure API auth)

If ARMember is unavailable, the endpoint returns `status: 0` with a dependency message instead of querying missing tables.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins** in wp-admin.
3. Open **Settings -> Bono ARM API**.
4. Enable the **List of Transactions** option.
5. Enable **Activate Member** if you want to expose the activation route.
6. Enable **Delete Member** if you want to expose the deletion route.
7. Install **Git Updater** if you want this site to track GitHub releases, which is the primary distribution channel for this plugin.

## Packaging

Build an installable plugin zip from the repo root:

```bash
./build.sh
```

That creates a Git Updater-compatible release asset like `bono_arm_api-x.y.z.zip`, containing the installable plugin folder `bono_arm_api/`.

## API specs

Checked-in API artifacts are available in the plugin and repo under:

- `docs/bono-arm-api-openapi.json`
- `docs/bono-arm-api-openapi-v2.json`
- `docs/bono-arm-api-postman-collection.json`

The repository includes both versioned OpenAPI contracts and the v1 Postman collection.

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
- requires `release-notes/x.y.z.md` with the standard release-note headings

Pushing the tag triggers GitHub Actions, which runs `./build.sh`, creates or updates the GitHub Release using the checked-in release notes file, and uploads the zip, SHA-256 checksum, and provenance attestation.

## Authentication

Endpoints require WordPress authentication and their dedicated capability. Administrators receive all three capabilities on activation. Member deletion additionally requires WordPress's object-level `delete_user` capability for the target account.

| Operation | Capability |
| --- | --- |
| Read payments | `bono_arm_api_read_payments` |
| Activate members | `bono_arm_api_activate_members` |
| Delete members | `bono_arm_api_delete_members` |

## Version 2 API

The v1 API remains supported without route or parameter changes. New integrations should prefer v2:

- `GET /wp-json/bono_armember/v2/payments?after_invoice_id=0&per_page=50`
- `POST /wp-json/bono_armember/v2/members/{user_id}/activate`
- `DELETE /wp-json/bono_armember/v2/members/{user_id}?reassign_user_id=456`

Payment responses provide `has_more` and `next_cursor`. Set `include_totals=true` only when an exact total is needed. The default `context=view` excludes payer email and administrative notes; trusted clients with the read capability can explicitly request `context=edit`.

WordPress 7 registers the private, read-only `bono-arm-api/get-status` ability. Destructive operations are intentionally not exposed through the Abilities API. See `docs/wordpress-7-compatibility.md` for the compatibility matrix and ARMember fixture policy.

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
| `arm_page` | integer | No | Page number (default: `1`, maximum: `10000`) |
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

### Activation endpoint

`POST /wp-json/bono_armember/v1/members/{user_id}/activate`

Optional JSON body:

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `send_email` | boolean | No | Send ARMember's manual activation email after the member is activated |

Example request:

```bash
curl -u your_username:your_app_password \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"send_email":true}' \
  "https://your-site.com/wp-json/bono_armember/v1/members/123/activate"
```

Example success response:

```json
{
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
}
```

### Deletion endpoint

`POST /wp-json/bono_armember/v1/members/{user_id}/delete`

No request body is required.

Example request:

```bash
curl -u your_username:your_app_password \
  -X POST \
  "https://your-site.com/wp-json/bono_armember/v1/members/123/delete"
```

Behavior:

- requires administrator authentication
- requires `bono_arm_api_delete_members` plus permission to delete the target user
- requires the member delete endpoint toggle to be enabled in plugin settings
- rejects deletion of the account authenticating the request
- currently supports single-site installs only
- reassigns the deleted member's content to the authenticated administrator
- uses `wp_delete_user()` as the primary deletion path
- relies on ARMember's `delete_user` and `deleted_user` lifecycle when available
- falls back to ARMember's explicit pre/post-delete methods only when those methods are loaded but the hooks are not attached

Example success response:

```json
{
  "status": 1,
  "message": "Member deleted successfully.",
  "response": {
    "result": {
      "user_id": 123,
      "user_login": "membername",
      "user_email": "member@example.com",
      "reassigned_to_user_id": 42,
      "cleanup_mode": "automatic_hooks"
    }
  }
}
```

### Common error responses

Errors use meaningful HTTP status codes: `400` for invalid input, `403` for disabled or forbidden operations, `404` for missing users, `500` for failed operations, `501` for unsupported multisite deletion, and `503` when ARMember dependencies are unavailable. WordPress authentication and REST argument validation errors use WordPress's standard REST error shape.

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
