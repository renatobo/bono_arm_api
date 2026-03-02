# Bono ARM API UI Notes

## Settings Header

- Use the banner image at `assets/bono-arm-api-settings-banner.svg` at the top of the settings page.
- Render the banner at its intended width instead of stretching it full-width.
- Keep the compact metadata row with these items:
  - `Plugin Repository`
  - current plugin version
  - author GitHub link
  - single-button link: `Updates via Git Updater`

## Settings Intro Copy

- Keep the page title `Bono ARM API Settings`.
- Keep the short descriptive paragraph focused on controlling the protected ARMember transactions endpoint.
- Keep the secondary line centered on external integrations and authenticated access.

## Tabs

- Use native WordPress tab markup:
  - `nav-tab-wrapper`
  - `nav-tab`
  - `nav-tab-active`
- Tabs are in-page panels, not separate admin pages.
- Switching tabs should:
  - show only the active panel
  - hide inactive panels with the `hidden` attribute
  - update the URL hash
  - restore the active tab from the URL hash on load

## Panel Layout

- Keep the layout WordPress-admin friendly, not app-like.
- Prefer flat cards, subtle borders, and native admin spacing.
- Keep the transactions toggle and endpoint examples on the first tab.
- Keep request parameters/response examples and Application Password instructions as separate tabs.

## Maintenance

- Keep WordPress-standard plugin asset filenames for update UI:
  - `assets/icon.svg`
  - `assets/icon-128x128.png`
  - `assets/icon-256x256.png`
- Keep these version references synchronized when cutting a release:
  - `bono-arm-api.php` plugin header `Version`
  - `bono-arm-api.php` constant `BONO_ARM_API_VERSION`
  - `readme.txt` `Stable tag`
- `Release Asset: true` depends on GitHub releases publishing a zip asset named with the repository slug prefix (`bono_arm_api-*.zip`).
- When the header or tabs design changes, update this file in the same change.
