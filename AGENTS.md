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
- Update `Stable tag` in `readme.txt` only when preparing a release.

## API Behavior Guardrails

- Endpoint: `GET /wp-json/bono_armember/v1/arm_payments_log`
- Required query param: `arm_invoice_id_gt`
- Optional query params: `arm_plan_id`, `arm_page`, `arm_perpage`
- Access is restricted to administrator users.
- API availability is controlled by plugin setting `bono_arm_api_enable_transactions`.

## Release Notes

- Packaging workflow is defined under `.github/workflows/`.
- Release zip expects plugin files at repo root, including `*.php`, `readme.txt`, and `LICENSE`.

