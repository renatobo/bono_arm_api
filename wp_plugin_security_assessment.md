# WordPress Plugin Security Assessment

Assessed version: 2.2.0 (September 2026 audit remediation).

## Executive Summary

- Scope: `bono-arm-api.php`, `includes/` (Plugin, Capabilities, Privacy, Abilities, Admin, ARMember, Infrastructure, REST), `uninstall.php`, admin assets, API specifications, documentation, packaging scripts, and GitHub Actions workflows.
- Overall security risk: **Low**.
- Open findings: Critical 0, High 0, Medium 0, Low 0. The v1 payments endpoint's unconditional payer data is tracked under Residual Gaps.
- All previously recorded authorization, deletion, REST validation/status, database-query, uninstall, conditional asset-loading, and minimum-version findings are remediated.

## Critical

No findings.

## High

No findings.

## Medium

No findings.

## Low

No findings.

## Resolved Findings

### SEC-2026-01 Member deletion reached administrators and triggered subscription cancellation without a trail

- Status: **Resolved** in 2.2.0.
- Original impact: the delete routes removed any WordPress user the caller could delete, including other administrators. ARMember's pre-delete cleanup cancels recurring gateway subscriptions, and nothing recorded the action.
- Resolution: users who can `manage_options` are refused with 403 unless the `bono_arm_api_can_delete_member` filter allows them. Every validation runs before ARMember cleanup. `bono_arm_api_member_deleted` and `bono_arm_api_member_activated` actions give sites an audit point.

### SEC-2026-02 `context=edit` exposed payer data to every payments reader

- Status: **Resolved** in 2.2.0 for v2.
- Resolution: `context=edit` requires the new `bono_arm_api_read_payer_details` capability and otherwise fails with 403 `rest_forbidden_context`. The settings screen states that v1 always returns payer data.

### SEC-2026-03 Activation wrote ARMember status onto non-members

- Status: **Resolved** in 2.2.0.
- Resolution: activation requires an `arm_members` row and returns 404 otherwise, so `arm_set_member_status()` no longer stamps status meta onto arbitrary accounts.

### SEC-2026-04 Updates re-granted capabilities an operator had removed

- Status: **Resolved** in 2.2.0.
- Resolution: a granted-capabilities list records what administrators already received. Updates grant only capabilities introduced since, and uninstall removes the capabilities from every role.

### DATA-2026-01 Payment dates mislabelled as UTC; notes could leak serialized data

- Status: **Resolved** in 2.2.0.
- Resolution: dates are converted from `wp_timezone()` to UTC. Notes are parsed in PHP from `arm_extra_vars` with `unserialize( ..., array( 'allowed_classes' => false ) )` instead of fixed-length SQL string surgery that returned the raw column on translated sites.

### WPCOMPAT-001 Minimum WordPress version conflicted with the documented authentication path

- Status: **Resolved**.
- Original impact: plugin metadata admitted WordPress versions that predate core Application Passwords, which the documentation presents as the normal authentication setup.
- Resolution: the minimum supported version is now WordPress 6.9 in `bono-arm-api.php`, `readme.txt`, `README.md`, `phpcs.xml.dist`, and `docs/wordpress-7-compatibility.md`. The floor is set by `wp_unique_id()` (6.8) in the settings screen and the Abilities API registration (6.9), and comfortably exceeds the 5.6 Application Passwords requirement.

## Completed Remediations

- REST access uses three dedicated least-privilege capabilities (`bono_arm_api_read_payments`, `bono_arm_api_activate_members`, `bono_arm_api_delete_members`) rather than `manage_options`. Member deletion additionally checks `delete_user` for the target account.
- Both API versions reject self-deletion in the permission callback. v1 reassigns content to the authenticated administrator; v2 requires an explicit `reassign_user_id` and validates that it exists and differs from the target.
- Member deletion refuses to run on multisite instead of attempting an unsupported delete path.
- REST integer arguments use strict positive/bounded validation. `arm_invoice_id_gt` is registered as required, page size is capped at 100, and page number is capped at 10000.
- The v2 payments endpoint withholds `payer_email` and `notes` unless the caller requests `context=edit`.
- Endpoint-controlled errors return meaningful HTTP 4xx/5xx status codes while v1 retains the plugin's legacy JSON envelope.
- ARMember table availability is cached for five minutes, avoiding repeated schema probes on each API call.
- The common payments path combines total-count and page retrieval into one query; empty deep pages retain an explicit count fallback. v2 uses keyset pagination and computes totals only when `include_totals` is requested.
- Every payment query is prepared exactly once. Table names use `%i` identifier placeholders and the shared WHERE clause returns unresolved `%d` placeholders with their values, replacing the previous pre-prepared fragment that an outer `prepare()` re-processed.
- Capabilities are granted on activation, removed on deactivation, and removed again on uninstall.
- Uninstall removes all feature-toggle options, the schema-version option, the table-availability transient, and the granted capabilities, iterating every site on multisite installs.
- Admin CSS and JavaScript are versioned external assets enqueued only on the plugin settings screen; inline event handlers were removed.
- A privacy-policy suggestion documents the personal data the endpoints can return.

## Positive Controls Confirmed

- Every PHP file blocks direct access with an `ABSPATH` guard.
- Every `register_rest_route()` call declares a real `permission_callback`; no route uses `__return_true`.
- Feature toggles gate each endpoint independently and fail closed when unset.
- Settings use `register_setting()` sanitization and the standard `options.php` nonce flow.
- No unauthenticated AJAX or REST mutation path was found.
- Request-controlled SQL values use `$wpdb->prepare()`, table names derive from `$wpdb->prefix` and are escaped as `%i` identifiers, and pagination is bounded. The `WordPress.DB.*` sniffs are enabled project-wide, with narrow justified suppressions only at the ARMember query sites.
- Admin output uses context-appropriate escaping, and external links use `noopener noreferrer`.
- Member deletion uses WordPress's `wp_delete_user()` and preserves ARMember pre/post-delete cleanup behavior.
- The single registered ability is read-only, capability-gated, and excluded from REST; destructive member actions are never exposed as abilities.
- No uploads, dynamic includes from request input, remote-fetch sinks, secrets, or shell execution are present in runtime plugin code. The one `unserialize()` call reads ARMember's own column with `allowed_classes => false`.

## Verification and Residual Gaps

- WordPress Coding Standards, PHP syntax checks across 7.4-8.5, PHPUnit, and the official Plugin Check run in `.github/workflows/quality.yml` and gate releases.
- The frozen v1 payments endpoint always returns payer email and notes to holders of `bono_arm_api_read_payments`. This is documented on the settings screen; sites delegating that capability should treat it as PII access.
- Payment reads, activation, deletion, and capability upgrades are covered by fixture tests with hand-written ARMember stand-ins (`tests/ArmemberFixtureTest.php`). A real ARMember install is still needed to verify the activation mailer, ARMember's registered delete hooks, and query plans.
- ARMember does not index `arm_invoice_id`, so both API versions scan and sort the payment log. The settings screen detects this and shows the index statement; the plugin does not alter ARMember's table itself. Offset pagination remains in v1 for backward compatibility.
- CI integration tests run WordPress 6.9 and 7.1, covering the declared floor and current tested version.
