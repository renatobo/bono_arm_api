# Bono API for ARMember

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![Tested up to](https://img.shields.io/badge/Tested%20up%20to-7.1-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![Release](https://img.shields.io/github/v/release/renatobo/bono_arm_api?label=release)](https://github.com/renatobo/bono_arm_api/releases)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

WordPress plugin that exposes protected REST API endpoints for ARMember payment logs, admin-triggered member activation, and guarded member deletion.

Current version: `2.3.0`

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

- WordPress `6.9+` (tested through WordPress `7.1`)
- PHP `7.4+`
- ARMember or ARMember Lite installed and active (either satisfies the requirement)
- HTTPS-enabled site (recommended for secure API auth)

ARMember is a required dependency. Without it the plugin still activates, but it grants none of
its capabilities, shows an admin notice, and its endpoints return Service Unavailable. The
capabilities are granted automatically on the first admin page load after ARMember is activated.
Activation itself is not blocked, because that would break WP-CLI, provisioning scripts, and the
official Plugin Check, all of which activate a plugin standalone.

The `Requires Plugins:` header is not used:
it resolves WordPress.org slugs against installed plugin directories and requires every listed
slug to be active, so naming either `armember` or `armember-membership` would make WordPress
deactivate this plugin on the sites running the other copy.

If ARMember is unavailable at request time, the endpoint returns `status: 0` with a dependency message instead of querying missing tables.

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

Prepare matching version metadata and `release-notes/X.Y.Z.md` in a PR into
`main`. Apply the **`release`** label before merging. GitHub runs the exact
merge commit through the existing CI gates and publishes its tested ZIP and
checksum automatically. See `AGENTS.md` for the complete procedure and retries.

## Authentication

Endpoints require WordPress authentication and their dedicated capability. Administrators receive every capability once, on activation or on the first update that introduces it. Removing a capability from the administrator role sticks: later updates do not grant it again. Member deletion additionally requires WordPress's object-level `delete_user` capability for the target account.

| Operation | Capability |
| --- | --- |
| Read payments | `bono_arm_api_read_payments` |
| Read payer email and notes (v2 `context=edit`) | `bono_arm_api_read_payer_details` |
| Activate members | `bono_arm_api_activate_members` |
| Delete members | `bono_arm_api_delete_members` |

The v1 payments endpoint always includes payer email and notes, so treat `bono_arm_api_read_payments` as access to personal data whenever v1 is in use.

## Version 2 API

The v1 API remains supported without route or parameter changes. New integrations should prefer v2:

- `GET /wp-json/bono_armember/v2/payments?after_invoice_id=0&per_page=50`
- `POST /wp-json/bono_armember/v2/members/{user_id}/activate`
- `DELETE /wp-json/bono_armember/v2/members/{user_id}?reassign_user_id=456`

Payment responses provide `has_more` and `next_cursor`. Set `include_totals=true` only when an exact total is needed. The default `context=view` excludes payer email and administrative notes. `context=edit` includes them and additionally requires `bono_arm_api_read_payer_details`; without it the request fails with `403 rest_forbidden_context`.

Payment dates in both API versions are ISO 8601 in UTC. ARMember stores them in site-local time, and the plugin converts them using the site's timezone setting.

ARMember does not index `arm_invoice_id`, so every payments request scans the whole payment log. On large logs, add the index shown on the plugin's settings screen (`ALTER TABLE wp_arm_payment_log ADD INDEX bono_invoice_id (arm_invoice_id);` with your table prefix). ARMember upgrades keep it.

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
| `arm_invoice_id_gt` | integer | Yes | Return records where invoice ID is greater than this value. Use `0` to start from the first invoice |
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

The user must have an ARMember member record. Other users get `404` with the message "The user is not an ARMember member."

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
- refuses users who can `manage_options` with `403`, unless the `bono_arm_api_can_delete_member` filter allows them
- currently supports single-site installs only
- reassigns the deleted member's content to the authenticated administrator
- uses `wp_delete_user()` as the primary deletion path
- relies on ARMember's `delete_user` and `deleted_user` lifecycle when available
- falls back to ARMember's explicit pre/post-delete methods only when those methods are loaded but the hooks are not attached
- ARMember's pre-delete cleanup cancels the member's recurring gateway subscriptions; this cannot be undone

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

## Hooks

| Hook | Type | Arguments | Purpose |
| --- | --- | --- | --- |
| `bono_arm_api_member_activated` | action | `$user_id`, `$send_email`, `$email_sent`, `$actor_id` | Runs after an API activation. Use it for audit logging. |
| `bono_arm_api_member_deleted` | action | `$user_id`, `$reassign_user_id`, `$actor_id`, `$user` (`WP_User` snapshot) | Runs after an API deletion. Use it for audit logging. |
| `bono_arm_api_can_delete_member` | filter | `$allowed`, `$user`, `$reassign_user_id` | Return `true` to allow, `false` or a `WP_Error` to refuse. Defaults to refusing users who can `manage_options`. |
| `bono_arm_api_payment_row` | filter | `$row`, `$raw_row` | Adjusts a normalized payment row before either API version maps it. Keep field types unchanged. |

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

## Renewal API

`POST /wp-json/bono_armember/v2/members/{user_id}/renewals` previews or commits one
externally funded natural subscription cycle. It reactivates the account, preserves the original
billing schedule, and creates exactly one successful manual payment or reuses an explicitly
identified matching payment. It records money already received; it does not charge the payer.

Enable **Renewal endpoints** and **Member activation endpoints** in Settings. The
caller needs both `bono_arm_api_renew_members` and `bono_arm_api_activate_members`, using normal
WordPress authentication. Renewal is disabled by default and currently supports the inspected
**ARMember Pro 8.1.1** manual recurring subscriptions only; other existing endpoints retain their
ARMember/Lite compatibility. Automatic debit, paid-finite/lifetime/free plans, cancelled agreements,
ambiguous restrictions, and payments that cannot restore entitlement in one cycle are rejected.

Send JSON with `plan_id` and `payment`; `dry_run` defaults to `true`. Example preview:

```json
{
  "plan_id": 12,
  "payment": {
    "mode": "create",
    "reference": "DNB-2026-member-123",
    "payer_name": "Ducati Newport Beach",
    "amount": "30.00",
    "currency": "USD",
    "paid_at": "2026-11-13T09:00:00-08:00"
  }
}
```

Use actual site IDs and the exact stored cycle total including tax. Review the projected dates
and payment, then repeat with `dry_run: false`, the returned `expected_state`, and a unique
`Idempotency-Key` header (16–128 printable ASCII characters without whitespace). The preview
expires after 15 minutes and is bound to the authenticated actor, command and relevant state.
A first completed commit returns 201; a completed replay returns 200 with the same operation and
payment IDs. Keep the original key and command after a timeout, even after the preview expires.

For an already recorded payment, use `payment: {"mode":"existing","payment_log_id":4567}`.
The record must be a successful manual subscription payment for the same member, plan, amount
and currency. Its Add Manual Payment **Note** must be a JSON object identifying the actual
`payer_name`, the stored `start_plan` timestamp and the projected `completed_cycles` count,
for example `{"payer_name":"Ducati Newport Beach","start_plan":1759276800,"completed_cycles":2}`.
Alternatively, an integration may record that object under `bono_renewal` in serialized
payment extras. Historical payments without explicit cycle purpose are rejected. The API never
edits or deletes the reused payment; the journal records its association.

`GET /wp-json/bono_armember/v2/renewals/{operation_id}` returns the durable operation and write
steps with the same capabilities, including when mutation toggles are disabled. All renewal
responses use `Cache-Control: no-store`. An in-progress lock conflict returns 409 and
`Retry-After: 5`. Partial or uncertain writes return 503
`bono_arm_api_renewal_needs_reconciliation`; inspect the operation before repairing it. An
interrupted `in_progress` operation is also blocked from automatic re-execution. Never attempt
recovery using another payment/reference or idempotency key.

The journal reserves external references, payment log IDs and member/plan/cycles in SQL, and API
writes are serialized per member. It intentionally survives plugin uninstall/reinstall to retain
payment-consumption evidence. Operators must reconcile uncertain operations against ARMember
before any manual journal repair. No automatic rollback of third-party hooks is claimed. API
locks do not serialize ARMember's portal, cron or third-party writers; avoid concurrent external
edits during settlement. The native invoice primitive and bookkeeping hooks are preserved.
ARMember mailers reached synchronously by the operation are suppressed; unrelated WordPress
mail remains enabled. Mailchimp, spreadsheets and caller-owned email remain outside this API.

See [the complete contract](docs/renewal-api-spec.md), the updated
[v2 OpenAPI specification](docs/bono-arm-api-openapi-v2.json), and the Postman preview/commit/status
examples. Validate installed ARMember hooks and renewal lifecycle in staging before enabling renewal mutations in production.

For a suspended target whose nonpayment cause has been explicitly confirmed by the operator,
send top-level `"suspension_reason": "nonpayment"` on preview and repeat it unchanged on
commit/replay. ARMember clears its grace reason after automatic suspension, and a later success
can hide the failed transaction. Without sufficient stored evidence, the API returns 422
`bono_arm_api_unsupported_subscription` with `data.reason: "suspension_reason_unverified"`.
Do not default this confirmation for all members: manual plan suspension can have the same
stored shape. Confirmation applies only to the target plan with an overdue schedule; it does
not bypass account restrictions, cancellation, unrelated grace actions or other-plan restrictions.
The confirmation is bound to the preview and idempotency command and retained in the journal.
Unsupported-subscription errors now include a machine-readable `data.reason`; malformed schedule
fields also identify `data.field`, without returning credentials or raw member metadata.


## Badge API

Enable **Badge endpoints** in Settings → Bono API. All badge routes require normal WordPress authentication and `bono_arm_api_manage_badges`, granted to administrators by default. The feature is disabled by default and requires ARMember badge support (inspected against Pro 8.1.1).

| Method | Route (under `/wp-json/bono_armember/v2`) | Purpose |
| --- | --- | --- |
| GET | `/badges` | List available badge definitions and find VIP’s `badge_id`. |
| GET | `/members/{user_id}/badges` | List the user’s stored badge assignments. |
| PUT | `/members/{user_id}/badges/{badge_id}` | Assign an existing badge if absent. |
| DELETE | `/members/{user_id}/badges/{badge_id}` | Remove all awards of that badge from this user. |

PUT and DELETE need no request body. For example, if VIP has ID 7, use `PUT /wp-json/bono_armember/v2/members/123/badges/7` to assign it and DELETE the same URL to remove it. Both return 200 with `user_id`, `badge_id`, `assigned`, and `changed`. Repeated requests are safe and return `changed: false` when already in the requested state. An existing automatic award also counts as assigned; PUT does not add a duplicate manual award.

List responses contain `badges`, an array of `{ "badge_id": 7, "name": "VIP", "icon": "…" }`. The user list reports stored assignments, including automatic awards whose display may currently be suppressed by ARMember. Removal preserves other badges and the badge definition; automatic rules may award the badge again later. This API does not change membership, roles, payments, or automatic award rules.

Errors use the WordPress REST shape: 400 invalid IDs, 401 unauthenticated, 403 missing capability, 404 missing user/badge (achievement rule IDs are not badge IDs), 409 malformed assignment data or conflicting/failed writes, and 503 disabled feature or unavailable badge support. Responses use `Cache-Control: no-store`. API mutations share the member lock with other Bono member operations; native admin/cron writers do not share it. Avoid concurrent external edits. On multisite, the user must belong to the current site.

## Compatibility review: ARMember 8.0 / Lite 6.2, WordPress 7.1.4

Premium: **Source-compatible; lifecycle regression needed**. Lite: **Source-compatible; lifecycle regression needed**. The API integration suite passes on real WordPress 7.1.4 (24 tests / 68 assertions), using ARMember fixtures. Installed premium/Lite testing remains pending. See [compatibility status and findings](COMPATIBILITY.md) and [local test setup](tests/README.md).

## Renewal reconciliation and privacy

`POST /wp-json/bono_armember/v2/renewals/{operation_id}/resolve` records an
operator's reconciliation of an interrupted or failed operation. It requires
`manage_options` plus both renewal and activation capabilities, even when mutation
toggles are off. Inspect the operation and ARMember payment and subscription state,
perform any necessary repair, then send JSON:

```json
{"confirm_reconciled":true,"cycle_stands":true,"payment_stands":true}
```

The booleans attest to the state after inspection or external repair; this endpoint
never repairs ARMember or replays writes. It records the operator ID and UTC time,
and changes the journal state to `resolved`. A live member lock returns 409 with
`Retry-After: 5`. An identical resolution is idempotent; contradictory resolutions
and attempts to resolve a completed operation return 409. The old idempotency key
returns 409 `bono_arm_api_operation_resolved` and cannot execute again.

Payment/reference reservations are always retained, including a payment an operator
has reversed. If `cycle_stands` is false, only the cycle reservation is released,
allowing a fresh preview with a new, independently verified payment reference after
repair. If the payment stands but the cycle does not, repair entitlement externally;
do not record the same money again under another reference. The endpoint trusts the
explicit administrator attestation; it is not an automatic reconciliation engine.

Payment is recorded and validated before entitlement is extended. Verification
failures record the named checkpoint and exception class, never exception messages.

WordPress privacy tools export member journal details and operator audit records.
Erasure removes personal payloads from completed/resolved member operations and
removes the requesting operator's ID from terminal audit records. Reservation hashes,
payment-log IDs, plan IDs and timestamps remain to prevent duplicate settlement;
these can still link to ARMember records and are not claimed to be anonymous.
Unresolved/locked operations retain details until reconciliation. ARMember's own
payment records are managed separately. Erased results return 410 on replay and
cannot execute again. Export/erase requests identify existing WordPress users by
email; process privacy requests before deleting their account. Uninstall retains
the journal. Site operators must define retention and separately handle payer
requests, since payer names have no email identity in this journal.


Renewal naming update: create commands and existing-payment purpose JSON require
`payer_name`; payment extras use `bono_renewal`. Routes, capabilities and settings
are unchanged. This is a breaking change from the development contract. Historical
payment records are not automatically rewritten; old-format records do not qualify
for existing-payment reuse. Preserve journal reservations and old commands for audit.
Obtain a fresh preview for a new command. Reconcile interrupted old operations before
starting any replacement; never disguise an already consumed payment with a new reference.
