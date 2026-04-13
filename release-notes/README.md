Create one markdown file per release in this directory using the version number as the filename, for example `1.1.0.md`.

Required top-level sections:

- `## New Features`
- `## Improvements`
- `## Bug Fixes`

These files are used by `release.sh` and the GitHub release workflow so the published GitHub Release includes explicit release notes instead of generated notes.
