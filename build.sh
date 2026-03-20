#!/bin/bash

set -euo pipefail

REPO_SLUG="$(basename "$PWD")"
PLUGIN_SLUG="bono_arm_api"
PLUGIN_FILE="bono-arm-api.php"

if [[ ! -f "$PLUGIN_FILE" ]]; then
  echo "Expected plugin bootstrap file '$PLUGIN_FILE' in $PWD"
  exit 1
fi

VERSION="$({ sed -n 's/^[[:space:]]*Version:[[:space:]]*//p' "$PLUGIN_FILE" || true; sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$PLUGIN_FILE" || true; } | head -n 1)"

if [[ -z "$VERSION" ]]; then
  echo "Could not determine plugin version from $PLUGIN_FILE"
  exit 1
fi

OUTPUT_NAME="${REPO_SLUG}-${VERSION}.zip"
OUTPUT_PATH="$PWD/$OUTPUT_NAME"
STAGING_DIR="$(mktemp -d)"
PACKAGE_DIR="$STAGING_DIR/$PLUGIN_SLUG"

cleanup() {
  rm -rf "$STAGING_DIR"
}

trap cleanup EXIT

mkdir -p "$PACKAGE_DIR"
rm -f "$OUTPUT_PATH"

rsync -a \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.claude/' \
  --exclude '.DS_Store' \
  --exclude '/output' \
  --exclude '/output/**' \
  --exclude '*.zip' \
  --exclude '.gitignore' \
  --exclude 'AGENTS.md' \
  --exclude 'README.md' \
  --exclude 'SECURITY.md' \
  --exclude 'build.sh' \
  --exclude 'release.sh' \
  --exclude 'ui.md' \
  ./ "$PACKAGE_DIR/"

rm -rf "$PACKAGE_DIR/output"

(
  cd "$STAGING_DIR"
  zip -rq "$OUTPUT_PATH" "$PLUGIN_SLUG"
)

echo "Created $OUTPUT_PATH"
