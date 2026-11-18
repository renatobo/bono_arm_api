# AGENTS.md

Guidance for coding agents and contributors working in this repository.

## Project Scope

- Project type: WordPress plugin providing a capability-gated REST API over ARMember payment and member data
- Main plugin file: `bono-arm-api.php`
- Plugin readme for WordPress packaging: `readme.txt`
- GitHub/project readme: `README.md`

## Development Rules

- Keep changes minimal and focused on the requested task.
- Preserve WordPress coding patterns and plugin compatibility.
- Do not change public REST route names or query parameter names unless explicitly requested.
- Keep `README.md` and `readme.txt` consistent when behavior or usage changes.
- Keep `AGENTS.md` aligned with repo behavior when distribution, release, or packaging rules change.
- Update `Stable tag` in `readme.txt` only when preparing a release.

## Distribution Rules

- Primary distribution channel: GitHub releases via Git Updater.
- Secondary distribution channel: WordPress.org, when that listing is available.
- Keep Git Updater-specific plugin header metadata in `bono-arm-api.php`.
- Production updates use `Primary Branch: stable` and `Release Asset: true`. Only the release workflow advances `stable`, after downloading and verifying the published assets. Development/version preparation stays on `main`.
- Branch migration uses `gu_config_pre_process` with a slug-indexed array of repository objects. Do not use `gu_fix_repo_slug`: newer Git Updater versions call it before owner/branch/type are available. Test against the pinned upstream implementations in CI, not invented hook payloads.
- Keep `Tested up to` only in `readme.txt`, never in the PHP plugin header.
- Keep Git Updater and distribution-channel UI/documentation copy consistent across `bono-arm-api.php`, `README.md`, and `readme.txt`.
- Do not describe WordPress.org as the default or primary update path unless explicitly requested.

## API Behavior Guardrails

- Endpoint: `GET /wp-json/bono_armember/v1/arm_payments_log`
- Required query param: `arm_invoice_id_gt`
- Optional query params: `arm_plan_id`, `arm_page`, `arm_perpage`
- Endpoint: `POST /wp-json/bono_armember/v1/members/{user_id}/activate`
- Optional activation body field: `send_email`
- Endpoint: `POST /wp-json/bono_armember/v1/members/{user_id}/delete`
- The delete route currently supports single-site installs only.
- Prefer ARMember's safer pre-delete and post-delete cleanup flow around `wp_delete_user()` instead of mirroring the current UI delete path literally.
- Access uses the existing operation capabilities, granted to administrators by default.
- Renewal: `POST /wp-json/bono_armember/v2/members/{user_id}/renewals`; status: `GET /wp-json/bono_armember/v2/renewals/{operation_id}`.
- Renewal requires renewal and activation capabilities/toggles; it supports the inspected Pro 8.1.1 manual recurring flow only.
- Preserve durable renewal journal reservations across uninstall/reinstall and never automatically replay an uncertain write.
- API availability is controlled by plugin setting `bono_arm_api_enable_transactions`.
- Member activation API availability is controlled by plugin setting `bono_arm_api_enable_member_activation`.

- Badge API: `GET /wp-json/bono_armember/v2/badges`, `GET /wp-json/bono_armember/v2/members/{user_id}/badges`, and `PUT`/`DELETE /wp-json/bono_armember/v2/members/{user_id}/badges/{badge_id}`.
- Badge endpoints require `bono_arm_api_manage_badges` and disabled-by-default `bono_arm_api_enable_badges`. Mutations are idempotent; remove all target awards while preserving other badges and definitions. Automatic rules may award removed badges again.

## Release Notes

- Packaging workflow is defined under `.github/workflows/`.
- The `quality.yml` workflow is the release gate; keep its WordPress/PHP matrix and immutable action pins current.
- Tagging and publishing use the labelled-PR workflow below. `release.sh` now refuses direct tagging; do not restore push-based stable tagging.
- Release zip expects plugin files at repo root, including `*.php`, `readme.txt`, `LICENSE`, and `languages/`.
- Regenerate the translation template with `wp i18n make-pot . languages/bono-arm-api.pot` whenever translatable strings change.
- Checked-in API specs live under `docs/` and should stay aligned with the plugin routes.
- GitHub Releases must publish the matching checked-in notes file from `release-notes/<version>.md`.

## GitHub releases after PR merge

The supported release path is `.github/workflows/release.yml`. Ordinary merges do not publish. The old tag/push/manual publishing workflows have been replaced; pushing a tag alone does not publish.

For the next release:

1. Bump `bono-arm-api.php` header `Version` and `BONO_ARM_API_VERSION` to matching `X.Y.Z`. Also update `readme.txt` Stable tag and any Version field. Keep the configured API-spec version fields synchronized (see `.github/release.json`). Update the user-facing changelog/upgrade notes under this repository’s conventions.
2. Add `release-notes/X.Y.Z.md` with exact headings `## New Features`, `## Improvements`, `## Bug Fixes`; use `- None.` if a section has no entries. Older releases without this notes format remain unchanged.
3. Open a PR into `main`, run all required checks, then apply **`release` before merging**. That label explicitly opts the merge into publication. Do not create/push the version tag yourself or use the retired direct-tagging `release.sh` path.
4. Merge. The workflow verifies the canonical merged PR and exact merge commit, runs all existing reusable quality gates plus release/package guards, checks versions/notes and main ancestry, creates annotated `vX.Y.Z`, and publishes the **exact CI-tested ZIP** with SHA-256 checksum. Build provenance attestation is retained. The release is created as a draft and published only after asset upload succeeds.
5. The workflow downloads the published release ZIP/checksum, verifies the tag commit, notes, GitHub digest and exact tested bytes, then fast-forwards `stable` to the release commit and reads it back. A repository-wide publication lock and ancestry/version guards prevent out-of-order releases. Read back the published release and uploaded checksum; a green PR is not evidence that publication completed. Expected ZIP: `bono_arm_api-{version}.zip` under root `bono-arm-api/`.

If publication fails, manually dispatch **Release merged PR** from `main` with `pr_number`. The PR must be merged into `main` and still carry `release`. Adding the label after merge does not trigger publication, but manual dispatch can release that eligible PR. Matching existing tags may be reused only when they point to the same merge commit. Existing releases/drafts are never overwritten. A published release may resume only when its tag, notes and downloaded assets match the exact tested artifact. Use **Re-run failed jobs** to retain the original package if publication succeeded but verification/promotion was interrupted; a fresh dispatch rebuilds the ZIP and may refuse an existing release with different bytes. Inspect and explicitly resolve partial drafts before retrying; never move/delete published tags as a retry strategy. All publication jobs are serialized across PRs. Never force-push `stable` or move it before publication verification.

The final result job reports failed/skipped gates as an incomplete release. `release-health.yml` runs every six hours and on manual dispatch to verify that the published release assets, tag, notes and production update branch agree. A green PR or merged version bump never proves publication.

Tags and publication happen in one workflow, avoiding the `GITHUB_TOKEN` tag-event suppression. No PAT or additional secret is required. Only publishing has `contents: write` plus OIDC/attestation permissions; quality jobs retain their own minimum analysis permissions. Only the Plugin Check analysis job (and its reusable-workflow caller) receives `pull-requests: write` to maintain the PR report; it has no contents write permission.

Release configuration is `.github/release.json`; guard code is `.github/scripts/release.py`; tests run with `python3 -B -m unittest discover -s .github/tests -v`. Package locally with `bash build.sh`, then run `python3 -B .github/scripts/release.py package`. Release ZIPs exclude this tooling, test caches, and agent guides. Do not hand-edit the upload/download handoff to rebuild a different artifact in the publishing job.

For workflow-only maintenance, keep the plugin version unchanged and omit the `release` label. Merge setup/automation PRs unlabelled; the next labelled version PR uses the new flow.

## Commands

```bash
composer check      # lint + phpcs + phpunit
composer phpcs      # WordPress Coding Standards (see gotcha below)
vendor/bin/phpcbf   # auto-fix
./build.sh          # release zip -> bono_arm_api-<version>.zip, folder bono-arm-api/
```

PHPUnit needs the WP test library and will fail locally without it:
`WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit`. CI is the real gate
(`.github/workflows/quality.yml`: phpcs, PHP 7.4-8.5 lint, WP 6.9 + 7.1 integration,
official Plugin Check). It triggers on pull requests and pushes to `main`/`wp7` only, so a
branch push alone runs nothing: open the PR to get CI.

## Architecture

`bono-arm-api.php` defines constants, registers an autoloader mapping `BonoArmApi\` to
`includes/`, and boots `Plugin::instance()`. No Composer autoload for plugin classes.

- `includes/REST/` — `V1_Controller` (legacy envelope, frozen), `V2_*` (schema-first)
- `includes/ARMember/` — ARMember integration gateways, including renewal settlement
- `includes/Infrastructure/` — payment and renewal repositories own database access
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
- **Never rename the packaged folder.** It is `bono-arm-api/`; the zip filename uses the repo
  dir (`bono_arm_api`). That mismatch is intentional, do not "fix" it. The folder is the
  upgrade key: 2.0.2 renamed it and Git Updater installed a second plugin beside the first
  instead of upgrading it.
- **Two installed copies share all stored data.** Options, capabilities, and transients are
  keyed by name, not directory. `uninstall.php` bails when a sibling copy is active; keep that
  guard. It is unreachable from PHPUnit, so test changes to it against stubbed WP functions.
- **Version checks use the release configuration.** `.github/scripts/release.py` reads
  the API spec fields configured in `.github/release.json`. The retired `release.sh`
  used literal `sed` and required a particular JSON key order and indentation; those
  formatting constraints no longer apply. Keep configured version values synchronized.
- **Plugin Check runs its own ruleset**, ignoring `phpcs.xml.dist` excludes, and rewrites a
  single PR comment with the report. Local phpcs clean does not mean Plugin Check clean.
- **`load_plugin_textdomain()` is deliberate**, warning suppression included: Git Updater
  installs get no language packs, so removing it breaks translations on the primary channel.
- **The settings page is under Settings**, so core's `options-head.php` already calls
  `settings_errors()`. Do not call it again — notices print twice.
- **Feature toggles fail closed.** Every endpoint checks its `bono_arm_api_enable_*`
  option and returns 503 when off. Tests must set the option. Exception: renewal status and administrator reconciliation
  remain available with both renewal and activation capabilities while mutations are disabled;
  reconciliation also requires manage_options,
  so operators can investigate interrupted writes. Status never installs the journal schema. Resolution records explicit operator attestation,
  never repeats ARMember writes, and preserves payment reservations.

## Translations

Regenerate after changing any translatable string (line references drift):
`wp i18n make-pot . languages/bono-arm-api.pot`
WP-CLI is not installed locally; run it wherever `wp` is available, or hand-verify with
`msgfmt --check`. Header metadata (Plugin Name, Description, Author) belongs in the POT too.

- The checked-in Stable tag stays at the last stable release until release preparation.
- WordPress privacy erasure removes terminal personal payloads, retaining duplicate-settlement
  reservations; unresolved operations require reconciliation first. Do not claim retained
  identifiers are anonymous.

- Renewal payment identity uses `payer_name` and extras key `bono_renewal`.
