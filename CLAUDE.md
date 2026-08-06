# CLAUDE.md

WordPress plugin: capability-gated REST API over ARMember payment and member data.
Read `AGENTS.md` first — it holds the binding rules on distribution, releases, and the
frozen v1 API contract. This file covers how to work in the repo.

## Commands

```bash
composer check      # lint + phpcs + phpunit
composer phpcs      # WordPress Coding Standards (see gotcha below)
vendor/bin/phpcbf   # auto-fix
./build.sh          # release zip -> bono_arm_api-<version>.zip, folder bono-arm-api/
```

PHPUnit needs the WP test library and will fail locally without it:
`WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit`. CI is the real gate
(`.github/workflows/quality.yml`: phpcs, PHP 7.4-8.5 lint, WP 6.9 + 7.0.2 integration,
official Plugin Check).

## Architecture

`bono-arm-api.php` defines constants, registers an autoloader mapping `BonoArmApi\` to
`includes/`, and boots `Plugin::instance()`. No Composer autoload for plugin classes.

- `includes/REST/` — `V1_Controller` (legacy envelope, frozen), `V2_*` (schema-first)
- `includes/ARMember/Gateway.php` — all ARMember globals and functions live here
- `includes/Infrastructure/Payment_Repository.php` — the only `$wpdb` code
- `includes/Admin/Settings_Page.php` — the single admin screen

## Gotchas

- **Read phpcs output in full.** `--report=summary | tail -N` hides the findings table.
- **SQL is linted; keep it that way.** `phpcs.xml.dist` no longer excludes the
  `WordPress.DB.*` sniffs. `Payment_Repository` suppresses them in narrow
  `phpcs:disable`/`enable` blocks with justifications. Table names use `%i` identifier
  placeholders and `where_clause()` returns unresolved `%d` placeholders plus their values,
  so every statement is prepared exactly once. Do not reintroduce a pre-prepared fragment.
- **WP floor is 6.9**, set by the Abilities API registration; `wp_unique_id()` in the
  settings page needs 6.8. CI no longer suppresses
  `wp_function_not_compatible_with_requires_wp`, and Plugin Check does not reason about
  `function_exists()` guards, so any newer core API forces the floor up.
- **Packaged folder is `bono-arm-api/`, repo dir is `bono_arm_api`.** The zip name uses
  the repo dir; the internal folder must match the text domain.
- **The settings page is under Settings**, so core's `options-head.php` already calls
  `settings_errors()`. Do not call it again — notices print twice.
- **Feature toggles fail closed.** Every endpoint checks its `bono_arm_api_enable_*`
  option and returns 503 when off. Tests must set the option.

## Translations

Regenerate after changing any translatable string (line references drift):
`wp i18n make-pot . languages/bono-arm-api.pot`
