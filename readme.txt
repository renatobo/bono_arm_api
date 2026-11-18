=== Bono API for ARMember ===
Contributors: renatobo
Tags: membership, subscriptions, payments, api, rest-api
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 2.3.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Capability-controlled REST API access to ARMember payment logs, member activation, and guarded deletion.

== Description ==

Bono API for ARMember adds protected endpoints to retrieve ARMember payment transactions, activate ARMember members, and safely delete members for external integrations.

Access control:
- Access uses dedicated least-privilege capabilities; deletion also checks permission for the target user.
- Payer email and notes on the v2 payments endpoint (`context=edit`) need the separate `bono_arm_api_read_payer_details` capability.
- The API refuses to delete users who can `manage_options` unless the `bono_arm_api_can_delete_member` filter allows it.
- Endpoint availability can be enabled/disabled in plugin settings.

Endpoint:
- GET /wp-json/bono_armember/v1/arm_payments_log
- POST /wp-json/bono_armember/v1/members/{user_id}/activate
- POST /wp-json/bono_armember/v1/members/{user_id}/delete

Version 2 endpoints:
- GET /wp-json/bono_armember/v2/payments
- POST /wp-json/bono_armember/v2/members/{user_id}/activate
- DELETE /wp-json/bono_armember/v2/members/{user_id}?reassign_user_id=456

Features:
- Filter by minimum invoice ID
- Optional filter by plan ID
- Pagination support for large datasets
- Cursor pagination in v2; totals are calculated only when `include_totals=true`
- Optional ARMember manual activation email on activation requests
- Guarded member deletion with capability checks, self-deletion protection, and content reassignment
- Checked-in OpenAPI 3.1 and Postman specs under `docs/`
- Compatible with WordPress Application Password authentication
- Returns successful transactions only
- Payment dates are ISO 8601 in UTC, converted from the site-local time ARMember stores
- Audit and extension hooks: `bono_arm_api_member_activated`, `bono_arm_api_member_deleted`, `bono_arm_api_can_delete_member`, `bono_arm_api_payment_row`
- Requires ARMember or ARMember Lite; without it no capabilities are granted and the endpoints return Service Unavailable
- Returns a `status: 0` dependency message if ARMember tables are unavailable

Automatic updates:
- This plugin receives GitHub release updates through Git Updater.
- The stable update branch advances only after the published ZIP and checksum are verified.
- WordPress.org can be used as a secondary channel when that listing is in place.
  https://github.com/afragen/git-updater

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin from wp-admin -> Plugins.
3. Go to Settings -> Bono ARM API.
4. Enable "List of Transactions".
5. Enable "Activate Member" if you want to expose the activation route.
6. Install and activate Git Updater for one-click updates from GitHub releases.

== Usage ==

Endpoint:
- GET `/wp-json/bono_armember/v1/arm_payments_log`
- POST `/wp-json/bono_armember/v1/members/{user_id}/activate`
- POST `/wp-json/bono_armember/v1/members/{user_id}/delete`

Required parameter:
- `arm_invoice_id_gt` (integer): return records with invoice ID greater than this value. Use `0` to start from the first invoice.

Optional parameters:
- `arm_plan_id` (integer): filter by ARMember plan ID.
- `arm_page` (integer): page number, default `1`, maximum `10000`.
- `arm_perpage` (integer): items per page, default `50`, maximum `100`.

Example requests:
- `https://yourwebsite.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450`
- `https://yourwebsite.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450&arm_plan_id=2&arm_page=2&arm_perpage=25`
- `POST https://yourwebsite.com/wp-json/bono_armember/v1/members/123/activate` with optional JSON body `{"send_email":true}`
- `POST https://yourwebsite.com/wp-json/bono_armember/v1/members/123/delete`

== Authentication ==

Use WordPress Application Passwords.

Setup:
1. Go to Users -> Profile.
2. In Application Passwords, create a new password.
3. Use your username + application password with Basic Auth.

Example curl:
`curl -u your_username:your_app_password "https://yourwebsite.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450"`

== API Specs ==

The plugin includes checked-in API artifacts at:

- `docs/bono-arm-api-openapi.json`
- `docs/bono-arm-api-openapi-v2.json`
- `docs/bono-arm-api-postman-collection.json`

The repository includes both versioned OpenAPI contracts and the v1 Postman collection.

== Upgrade Notice ==

= 2.3.2 =
Corrects production branch migration for newer Git Updater versions. If a cached source download fails with Unsupported Media Type, refresh Git Updater data or upload this release ZIP and replace the existing plugin.

= 2.3.1 =
Repairs the repeated 2.3.0 update offer. Git Updater now follows verified releases on stable. Includes the renewal and badge APIs from 2.3.0; review its upgrade notes when upgrading from 2.2.0.

= 2.3.0 =
Renewal requests now require payer_name; payment extras use bono_renewal. Update clients and obtain fresh previews. Includes audited reconciliation and privacy tools. Settings is now ARMember BonoAPI.

= 2.3.0-beta.3 =
Adds badge listing, assignment and removal endpoints. Enable Badge endpoints and use an account with bono_arm_api_manage_badges. Automatic rules may award a removed badge again.

= 2.3.0-beta.2 =
Adds explicit per-member nonpayment confirmation for ambiguous automatic plan suspensions and precise rejection reasons. Only send suspension_reason=nonpayment after the operator confirms the target cause; repeat it unchanged on preview/commit/replay.

= 2.3.0-beta =
Beta for staging validation of renewals on ARMember Pro 8.1.1. Renewal is disabled by default and requires both renewal and activation capabilities/settings. Review preview results before committing; reconcile uncertain operations before retrying.

= 2.2.0 =
Changes v1 output: payment dates are now converted to UTC and notes are parsed correctly. The API refuses to delete administrators, and v2 context=edit needs the new bono_arm_api_read_payer_details capability. Review integrations before updating.

= 2.1.2 =
Requires PHP_CodeSniffer 3.13.6 or newer in development environments and confirms compatibility through WordPress 7.1. No plugin runtime changes.

= 2.1.1 =
Updates the PHP_CodeSniffer development dependency to resolve GHSA-hmqg-cxww-wqhq. No functional changes; the affected package is never shipped in the plugin zip.

= 2.1.0 =
Makes ARMember a required dependency. Either ARMember or ARMember Lite satisfies it. Existing installs are unaffected: without ARMember the plugin still activates but grants no capabilities and shows an admin notice.

= 2.0.3 =
Stops uninstall from deleting shared settings and capabilities when a second copy of the plugin is still active. Install this before removing a duplicate copy left behind by the 2.0.2 folder rename.

= 2.0.2 =
Raises the minimum WordPress version to 6.9, removes plugin capabilities on deactivation, cleans up every site on multisite uninstall, and ships translation support. The packaged plugin folder is now `bono-arm-api`.

= 2.0.1 =
Updates the WordPress Coding Standards development dependency to resolve CVE-2026-45293.

= 2.0.0 =
Adds the schema-first v2 API, least-privilege capabilities, WordPress 7 integration, and a fully verified release-quality workflow while preserving all v1 routes.

= 1.2.0 =
Hardens REST authorization and deletion safeguards, improves payment-query efficiency and validation, and conditionally loads extracted admin assets.

= 1.0.9 =
Clarifies GitHub-first distribution with Git Updater metadata and aligns admin and readme copy for the dual-channel release model.

== Frequently Asked Questions ==

= Who can access the endpoint? =
Administrators receive `bono_arm_api_read_payments`, `bono_arm_api_read_payer_details`, `bono_arm_api_activate_members`, and `bono_arm_api_delete_members` once, on activation or on the first update that introduces them, and the plugin removes them again on deactivation. Removing a capability from the administrator role sticks across updates. Integrations can be granted only the capabilities they require. Member deletion also requires WordPress permission to delete the target user. Uninstall removes the capabilities from every role.

= How can I disable the endpoint? =
Go to Settings -> Bono ARM API and uncheck the endpoint toggle you want to disable.

= What does the activation endpoint do? =
It activates the specified ARMember member by setting them to active status, clears any activation key, and can optionally send ARMember's manual activation email. Users without an ARMember member record get a 404.

= Is there a delete-member endpoint? =
Yes.

The protected `POST /wp-json/bono_armember/v1/members/{user_id}/delete` route deletes the WordPress user on single-site installs and preserves ARMember's safer cleanup lifecycle around `wp_delete_user()`. It rejects self-deletion, refuses users who can `manage_options` unless the `bono_arm_api_can_delete_member` filter allows them, verifies WordPress's object-level delete capability, and reassigns content to the authenticated administrator. ARMember's cleanup cancels the member's recurring gateway subscriptions. It uses ARMember's registered delete hooks when they are active and falls back to ARMember's explicit pre-delete and post-delete methods only when those methods are loaded but the hooks are not attached.

= What happens if `arm_invoice_id_gt` is missing? =
The API responds with `status: 0` and a message indicating the missing parameter.

= Why is the payments endpoint slow on a large site? =
ARMember does not index `arm_invoice_id`, so each request scans the whole payment log. The plugin's settings screen shows the `ALTER TABLE` statement that adds the index. ARMember upgrades keep it.

= What happens if ARMember is inactive or missing? =
The API responds with `status: 0` and a message indicating that ARMember must be installed and active.

== Changelog ==

= 2.3.2 =
* Migrates the resolved Git Updater repository objects before remote checks and update generation.
* Tests the integration against pinned legacy and current upstream Git Updater implementations.

= 2.3.1 =
* Fixed the Plugin Check header error that prevented 2.3.0 publication.
* Moved production update checks to stable, promoted only after published package verification.
* Added publication read-back, safe retries, release failure summaries, and scheduled release health checks.

= 2.3.0 =
* Added audited renewal reconciliation, named verification diagnostics, privacy export/erasure, and payment-first settlement.
* Renamed the renewal API payer field and metadata; synchronized contracts and client guidance.
* Matched the settings page to the WPBono plugin family with a banner and responsive cards.
* Renamed the Settings menu to ARMember BonoAPI and refreshes invoice-index detection to hide obsolete notices.

= 2.3.0-beta.3 =
* Added authenticated v2 badge catalog, per-user badge list, and idempotent assignment/removal with a dedicated capability and disabled-by-default setting.
* Added badge API contracts, translation strings, and tests for permissions, idempotency, preservation of other awards, and conflicting writes.

= 2.3.0-beta.2 =
* Fixed blocked renewal when ARMember cleared the automatic-suspension grace reason and a later successful payment hid the earlier failure, using explicit operator confirmation instead of inferring the cause.
* Preserved cancellation, administrative, unrelated grace and other-plan guards; confirmed target suspension must be overdue.
* Added precise unsupported-subscription reason codes and regression coverage.

= 2.3.0-beta =
* Added v2 renewal preview/commit and operation status endpoints.
* Reactivates the account, advances one natural billing cycle, and creates or explicitly reuses one payment.
* Added a disabled-by-default renewal setting, dedicated capability, durable journal, idempotency, concurrency locks, and reconciliation handling.
* Added native-helper integration tests, API examples, and journal retention/privacy documentation.

= 2.2.0 =
* Added the `bono_arm_api_read_payer_details` capability, required for v2 `context=edit`. Existing administrators receive it on update.
* Added `bono_arm_api_member_activated` and `bono_arm_api_member_deleted` actions, and `bono_arm_api_can_delete_member` and `bono_arm_api_payment_row` filters.
* The API now refuses to delete users who can `manage_options` unless the `bono_arm_api_can_delete_member` filter allows it, and runs every validation before ARMember's subscription-cancelling cleanup.
* Activation returns 404 for users without an ARMember member record instead of reporting success.
* Fixed payment dates, which carried site-local time labelled as UTC, in both API versions.
* Fixed `notes`, which matched only the English "Paid By admin" label and could return raw serialized data on translated sites.
* The v1 payments endpoint accepts `arm_invoice_id_gt=0`.
* Capabilities are granted once, so updates no longer restore a capability removed from administrators. Uninstall removes them from every role.
* The settings screen detects ARMember's missing `arm_invoice_id` index and shows the statement that adds it.
* Autoloaded the schema-version option, cleared the table probe cache when ARMember is activated, and logged database failures under `WP_DEBUG_LOG`.

= 2.1.2 =
* Raised the minimum `squizlabs/php_codesniffer` development dependency to 3.13.6 so future Composer resolutions cannot select a version affected by GHSA-hmqg-cxww-wqhq. The package remains excluded from release zips.
* Confirmed compatibility through WordPress 7.1 and updated the release-gate integration matrix accordingly.

= 2.1.1 =
* Updated the `squizlabs/php_codesniffer` development dependency to 3.13.6 to resolve GHSA-hmqg-cxww-wqhq. The package is a development-only dependency and is excluded from the release zip, so no released build was affected.

= 2.1.0 =
* ARMember is now a required dependency. When neither ARMember nor ARMember Lite is active the plugin grants none of its capabilities, so it has no reachable API surface on a site that cannot serve one. The capabilities are granted automatically on the first admin page load after ARMember is activated.
* Added an admin notice for the case where ARMember is deactivated while this plugin is already active.
* Dependency detection accepts either the premium ARMember (`armember`) or ARMember Lite (`armember-membership`), using the directory constant each defines at the top of its main plugin file.
* The `Requires Plugins:` header is deliberately not used: it resolves WordPress.org slugs against installed plugin directories and requires every listed slug to be active, so declaring either slug would deactivate this plugin on sites running the other copy.

= 2.0.3 =
* Uninstall now leaves stored data alone when another copy of the plugin is still installed and active, so removing a duplicate no longer wipes the live copy's feature toggles, schema version, and capabilities.
* Corrected the 2.0.2 upgrade guidance: the packaged folder rename installs as a second plugin under Git Updater rather than updating in place.

= 2.0.2 =
* Raised the minimum supported WordPress version to 6.9, the floor required by the Abilities API and the settings screen.
* Removed the plugin capabilities on deactivation instead of leaving them on the administrator role until uninstall.
* Extended uninstall cleanup to every site on multisite installs.
* Added a translation template and registered the plugin language directory so translations load on GitHub installs.
* Renamed the packaged plugin folder to `bono-arm-api` so it matches the text domain. Git Updater installs this as a second plugin rather than updating in place; see the 2.0.3 upgrade notice.
* Rejected self-deletion in the v1 delete permission callback instead of relying on the reassignment check.
* Registered the v2 payment item schema so the route is introspectable, and expanded the v2 OpenAPI specification.
* Corrected duplicated settings notices and the ARMember availability notice severity on the settings screen.
* Listed every registered route on the settings screen and labelled the endpoint copy buttons for assistive technology.
* Hardened the ARMember payment queries with identifier placeholders and single-pass statement preparation.

= 2.0.1 =
* Updated WordPress Coding Standards to 3.4.1 and refreshed its compatible development dependencies.
* Resolved CVE-2026-45293 in the Composer development dependency lockfile.

= 2.0.0 =
* Added a schema-first v2 API with cursor pagination, field contexts, standard errors, and HTTP DELETE semantics.
* Added dedicated capabilities, privacy-policy guidance, and a safe read-only WordPress Abilities API status operation.
* Split the plugin into focused controllers, repository, ARMember gateway, admin, and compatibility modules.
* Added WordPress 7/PHP compatibility CI, REST integration tests, WPCS, Plugin Check, checksums, and release attestations.

= 1.2.0 =
* Hardened REST authorization and member-deletion safeguards.
* Improved payment-query efficiency, argument validation, and conditional admin asset loading.

= 1.0.9 =
* Clarified GitHub-first distribution through Git Updater, with WordPress.org documented as the secondary channel when available.
* Updated plugin UI, repository docs, and agent guidance to keep release/distribution copy consistent.

= 1.0.8 =
* Added graceful ARMember dependency checks so the endpoint returns a controlled error when ARMember tables are unavailable.
* Added text-domain metadata and localized plugin UI/API strings for translation readiness.
* Confirmed compatibility through WordPress 6.9.4 and synced release documentation.
* Prepared standard WordPress.org readme metadata and upgrade notice content.
* Fixed build packaging so local validation artifacts are excluded from release archives.

= 1.0.6 =
* Synced version metadata across `README.md`, `readme.txt`, and the plugin header for release consistency.
* Updated the release script to keep the GitHub/project README version aligned on future releases.

= 1.0.5 =
* Fixed the release package slug to `bono_arm_api` so WordPress updates the existing plugin instead of creating a duplicate entry.
* Cleaned the release workflow to ignore generated zip artifacts and remove previously tracked release archives.

= 1.0.4 =
* Hardened the release workflow against shell injection in manually dispatched version inputs.
* Capped `arm_perpage` at 100 records per request to reduce admin-only endpoint abuse risk.

= 1.0.2 =
* Stable release for ARMember payment log endpoint.
* Settings toggle for endpoint enable/disable.
* Pagination and plan filter support.
* Administrator-only permission check.
* Git Updater compatibility metadata.

= 1.0.0 =
* Initial release.

== License ==

This plugin is licensed under GPLv2 or later.

== Renewal API ==

POST /wp-json/bono_armember/v2/members/{user_id}/renewals previews or commits one natural externally paid renewal cycle, reactivates the account, and creates one successful manual transaction or explicitly reuses a matching existing payment. This records external money already received; it does not charge the payer.

Enable Renewal endpoints and Member activation endpoints. Both bono_arm_api_renew_members and bono_arm_api_activate_members are required. Renewal is disabled by default and initially supports the inspected ARMember Pro 8.1.1 manual recurring subscriptions only. Other endpoints retain their existing ARMember/Lite support. Automatic debit, free/lifetime/paid-finite plans, cancellations, ambiguous restrictions, and renewals still overdue after one cycle are rejected.

Send JSON containing plan_id and payment. dry_run defaults to true. payment.mode=create requires reference, payer_name, positive decimal-string amount, matching currency and paid_at with an explicit timezone. The total must match the stored cycle including tax. Review the preview, then repeat with dry_run=false, expected_state from the preview, and an Idempotency-Key header of 16-128 printable ASCII characters without whitespace. Previews expire after 15 minutes. Preserve the original key and command after timeouts; completed replays return the same operation/payment IDs even after preview expiry.

payment.mode=existing requires payment_log_id and no create-only fields. The payment must belong to the same member/plan, be a successful manual subscription payment, and match amount/currency. Its Add Manual Payment Note must be JSON with payer_name, start_plan (stored timestamp), and completed_cycles (projected count), or the same object must exist in serialized extras under bono_renewal. Payments without explicit next-cycle purpose are rejected. Existing payments are never edited or deleted.

GET /wp-json/bono_armember/v2/renewals/{operation_id} reads the durable operation using both capabilities, even with mutation toggles disabled. Renewal responses use Cache-Control: no-store. Lock conflicts return 409 and Retry-After: 5. Partial/uncertain writes return 503 bono_arm_api_renewal_needs_reconciliation with operation_id and known steps; interrupted in_progress operations also block automatic re-execution. Inspect and reconcile before repair; do not retry with a new key or payment.

The SQL journal reserves references, payment IDs and member/plan/cycles and survives uninstall/reinstall to preserve consumption evidence. API writes serialize per member; portal/cron/third-party writes do not share these locks, so avoid concurrent external edits. No rollback of third-party hooks is claimed. Native invoice/bookkeeping hooks are retained. ARMember mailers reached synchronously by the operation are suppressed; unrelated WordPress mail remains enabled. Mailchimp, spreadsheets and caller-owned email are outside this API. Full examples, recovery guidance and the contract are in the GitHub README and docs/renewal-api-spec.md; v2 OpenAPI and Postman examples are updated.

For an ambiguous suspended target, 422 bono_arm_api_unsupported_subscription includes data.reason=suspension_reason_unverified. After explicit per-member operator confirmation only, add top-level "suspension_reason":"nonpayment" to the preview and repeat unchanged on commit/replay. Never default this for all members: ARMember can store manual and automatic suspension in the same shape. The confirmation is journaled and bound to the command; the target must be overdue, and cancellation, account restrictions, unrelated grace actions and other-plan restrictions remain protected. Unsupported-subscription errors include data.reason; malformed schedule fields also include data.field without raw metadata or credentials.

== Badge API ==

Enable Badge endpoints in Settings > Bono API. Disabled by default; requires ARMember badge support (inspected Pro 8.1.1), WordPress authentication and bono_arm_api_manage_badges, granted to administrators by default.

GET /wp-json/bono_armember/v2/badges lists available definitions (badge_id, name, icon). GET /wp-json/bono_armember/v2/members/{user_id}/badges lists stored assignments, including automatic awards whose display may be suppressed. PUT /wp-json/bono_armember/v2/members/{user_id}/badges/{badge_id} assigns a badge if absent. DELETE the same route removes every award of that badge for this user. Neither mutation needs a body. Both return 200 with user_id, badge_id, assigned and changed; repeats return changed=false. PUT treats an existing automatic award as assigned.

Removal preserves other badges and the definition. Automatic rules may award it again. Membership, roles, payments and award rules are unchanged. API writes share the member lock; avoid concurrent native admin/cron edits. Multisite targets must belong to the current site. All responses use Cache-Control: no-store. Errors: 400 invalid IDs, 401/403 authentication/permission, 404 user/badge missing, 409 malformed state or conflicting/failed write, 503 disabled/unavailable. See the GitHub README and v2 OpenAPI/Postman contracts.


== Renewal reconciliation and privacy ==

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
