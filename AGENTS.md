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
- Access is restricted to administrator users.
- API availability is controlled by plugin setting `bono_arm_api_enable_transactions`.

## Release Notes

- Packaging workflow is defined under `.github/workflows/`.
- Release zip expects plugin files at repo root, including `*.php`, `readme.txt`, and `LICENSE`.
