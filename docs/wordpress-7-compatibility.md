# WordPress 7 compatibility

The `wp7` work keeps every documented v1 route and query parameter stable while adding a schema-first v2 API.

## Compatibility policy

- Runtime: WordPress 6.9 or newer and PHP 7.4 or newer.
- CI: PHP 7.4, 8.0, 8.3, 8.4, and 8.5 syntax checks.
- REST integration: WordPress 6.9 with PHP 7.4, and WordPress 7.0.2 with PHP 8.3 and 8.5 under `WP_DEBUG`.
- Quality: WordPress Coding Standards, PHPCompatibility, PHPUnit, and official Plugin Check.
- ARMember: open-source CI validates dependency-unavailable behavior. Licensed integration environments should provide ARMember and exercise its payment tables and member lifecycle hooks.

## WordPress 7 integration

The plugin relies on just-in-time translation loading, adds privacy-policy guidance, conditionally enqueues admin assets, and registers one safe read-only Abilities API operation when that API exists. Destructive member actions are intentionally REST-only and are never exposed as abilities.
