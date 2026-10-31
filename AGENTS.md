# AGENTS.md

Guidance for coding agents and contributors working in this repository.

## Project Scope

- Project type: WordPress plugin
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
- Access is restricted to administrator users.
- API availability is controlled by plugin setting `bono_arm_api_enable_transactions`.
- Member activation API availability is controlled by plugin setting `bono_arm_api_enable_member_activation`.

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
5. Read back the published release and uploaded checksum; a green PR is not evidence that publication completed. Expected ZIP: `bono_arm_api-{version}.zip` under root `bono-arm-api/`.

If publication fails, manually dispatch **Release merged PR** from `main` with `pr_number`. The PR must be merged into `main` and still carry `release`. Adding the label after merge does not trigger publication, but manual dispatch can release that eligible PR. Matching existing tags may be reused only when they point to the same merge commit. Existing releases/drafts are never overwritten: inspect and explicitly resolve partial drafts before retrying; never move/delete published tags as a retry strategy. Concurrent runs for the same PR are serialized.

Tags and publication happen in one workflow, avoiding the `GITHUB_TOKEN` tag-event suppression. No PAT or additional secret is required. Only publishing has `contents: write` plus OIDC/attestation permissions; quality jobs retain their own minimum analysis permissions.

Release configuration is `.github/release.json`; guard code is `.github/scripts/release.py`; tests run with `python3 -B -m unittest discover -s .github/tests -v`. Package locally with `bash build.sh`, then run `python3 -B .github/scripts/release.py package`. Release ZIPs exclude this tooling and agent guides. Do not hand-edit the upload/download handoff to rebuild a different artifact in the publishing job.

For workflow-only maintenance, keep the plugin version unchanged and omit the `release` label. Merge setup/automation PRs unlabelled; the next labelled version PR uses the new flow.
