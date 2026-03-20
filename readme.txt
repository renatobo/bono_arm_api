=== Bono API for ARMember ===
Tags: membership, subscriptions, payments, api, rest-api
Requires at least: 5.0
Tested up to: 6.9.4
Stable tag: 1.0.9
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin-only REST API access to ARMember payment logs with filtering and pagination.

== Description ==

Bono API for ARMember adds a protected endpoint to retrieve ARMember payment transactions for external integrations.

Access control:
- Endpoint access is restricted to WordPress administrators.
- Endpoint availability can be enabled/disabled in plugin settings.

Endpoint:
- GET /wp-json/bono_armember/v1/arm_payments_log

Features:
- Filter by minimum invoice ID
- Optional filter by plan ID
- Pagination support for large datasets
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
5. Install and activate Git Updater for one-click updates from GitHub releases.

== Usage ==

Endpoint:
- GET `/wp-json/bono_armember/v1/arm_payments_log`

Required parameter:
- `arm_invoice_id_gt` (integer): return records with invoice ID greater than this value.

Optional parameters:
- `arm_plan_id` (integer): filter by ARMember plan ID.
- `arm_page` (integer): page number, default `1`.
- `arm_perpage` (integer): items per page, default `50`, maximum `100`.

Example requests:
- `https://yourwebsite.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450`
- `https://yourwebsite.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450&arm_plan_id=2&arm_page=2&arm_perpage=25`

== Authentication ==

Use WordPress Application Passwords.

Setup:
1. Go to Users -> Profile.
2. In Application Passwords, create a new password.
3. Use your username + application password with Basic Auth.

Example curl:
`curl -u your_username:your_app_password "https://yourwebsite.com/wp-json/bono_armember/v1/arm_payments_log?arm_invoice_id_gt=1450"`

== Upgrade Notice ==

= 1.0.9 =
Clarifies GitHub-first distribution with Git Updater metadata and aligns admin and readme copy for the dual-channel release model.

== Frequently Asked Questions ==

= Who can access the endpoint? =
Only users with the administrator role.

= How can I disable the endpoint? =
Go to Settings -> Bono ARM API and uncheck "List of Transactions".

= What happens if `arm_invoice_id_gt` is missing? =
The API responds with `status: 0` and a message indicating the missing parameter.

= What happens if ARMember is inactive or missing? =
The API responds with `status: 0` and a message indicating that ARMember must be installed and active.

== Changelog ==

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
