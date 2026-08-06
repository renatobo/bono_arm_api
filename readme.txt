=== Bono API for ARMember ===
Contributors: renatobo
Tags: membership, subscriptions, payments, api, rest-api
Requires at least: 6.8
Tested up to: 7.0
Stable tag: 2.0.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Capability-controlled REST API access to ARMember payment logs, member activation, and guarded deletion.

== Description ==

Bono API for ARMember adds protected endpoints to retrieve ARMember payment transactions, activate ARMember members, and safely delete members for external integrations.

Access control:
- Access uses dedicated least-privilege capabilities; deletion also checks permission for the target user.
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
- Returns a `status: 0` dependency message if ARMember tables are unavailable

Automatic updates:
- This plugin is set up for GitHub-distributed updates through Git Updater.
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
- `arm_invoice_id_gt` (integer): return records with invoice ID greater than this value.

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

= 2.0.2 =
Raises the minimum WordPress version to 6.8, removes plugin capabilities on deactivation, cleans up every site on multisite uninstall, and ships translation support. The packaged plugin folder is now `bono-arm-api`.

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
Administrators receive `bono_arm_api_read_payments`, `bono_arm_api_activate_members`, and `bono_arm_api_delete_members` on activation, and the plugin removes them again on deactivation. Integrations can be granted only the capabilities they require. Member deletion also requires WordPress permission to delete the target user.

= How can I disable the endpoint? =
Go to Settings -> Bono ARM API and uncheck the endpoint toggle you want to disable.

= What does the activation endpoint do? =
It activates the specified ARMember member by setting them to active status, clears any activation key, and can optionally send ARMember's manual activation email.

= Is there a delete-member endpoint? =
Yes.

The protected `POST /wp-json/bono_armember/v1/members/{user_id}/delete` route deletes the WordPress user on single-site installs and preserves ARMember's safer cleanup lifecycle around `wp_delete_user()`. It rejects self-deletion, verifies WordPress's object-level delete capability, and reassigns content to the authenticated administrator. It uses ARMember's registered delete hooks when they are active and falls back to ARMember's explicit pre-delete and post-delete methods only when those methods are loaded but the hooks are not attached.

= What happens if `arm_invoice_id_gt` is missing? =
The API responds with `status: 0` and a message indicating the missing parameter.

= What happens if ARMember is inactive or missing? =
The API responds with `status: 0` and a message indicating that ARMember must be installed and active.

== Changelog ==

= 2.0.2 =
* Raised the minimum supported WordPress version to 6.8, which the settings screen already required.
* Removed the plugin capabilities on deactivation instead of leaving them on the administrator role until uninstall.
* Extended uninstall cleanup to every site on multisite installs.
* Added a translation template and registered the plugin language directory so translations load on GitHub installs.
* Renamed the packaged plugin folder to `bono-arm-api` so it matches the text domain.
* Rejected self-deletion in the v1 delete permission callback instead of relying on the reassignment check.
* Registered the v2 payment item schema so the route is introspectable, and expanded the v2 OpenAPI specification.
* Corrected duplicated settings notices and the ARMember availability notice severity on the settings screen.

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
