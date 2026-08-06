# WordPress Plugin Security Assessment

Assessed version: 2.0.2 (post-review remediation).

## Executive Summary

- Scope: `bono-arm-api.php`, `includes/` (Plugin, Capabilities, Privacy, Abilities, Admin, ARMember, Infrastructure, REST), `uninstall.php`, admin assets, API specifications, documentation, packaging scripts, and GitHub Actions workflows.
- Overall security risk: **Low**.
- Open findings: Critical 0, High 0, Medium 0, Low 0. One accepted exposure is tracked under Residual Gaps (`context=edit` payer data).
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
- No uploads, dynamic includes from request input, unsafe deserialization, remote-fetch sinks, secrets, or shell execution are present in runtime plugin code.

## Verification and Residual Gaps

- WordPress Coding Standards, PHP syntax checks across 7.4-8.5, PHPUnit, and the official Plugin Check run in `.github/workflows/quality.yml` and gate releases.
- `context=edit` on the v2 payments endpoint exposes payer email and notes to any holder of `bono_arm_api_read_payments`. This is intentional and documented on the settings screen, but sites delegating that capability broadly should treat it as PII access.
- The plugin has not been executed in a full WordPress + ARMember environment, so capability mapping, activation email behavior, ARMember cleanup hooks, SQL query plans, and `WP_DEBUG` runtime notices require integration verification.
- Offset pagination remains in v1 for backward compatibility. It is bounded, but production-like `EXPLAIN` checks are still recommended for high-volume ARMember tables.
- CI integration tests run WordPress 6.9 and 7.0.2, which now match the declared floor.
