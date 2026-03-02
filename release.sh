#!/bin/bash

set -euo pipefail

VERSION="${1:-}"

if [[ -z "$VERSION" ]]; then
  read -r -p "Enter new version (e.g. 1.2.1): " VERSION
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Invalid version format. Use semantic versioning: X.Y.Z"
  exit 1
fi

TAG="v$VERSION"

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "Working tree is not clean. Commit or stash changes before running a release."
  exit 1
fi

if git rev-parse "$TAG" >/dev/null 2>&1; then
  echo "Tag $TAG already exists."
  exit 1
fi

update_file() {
  local file_path="$1"
  local search_pattern="$2"
  local replacement="$3"
  local tmp_file

  tmp_file="$(mktemp)"
  sed "s/${search_pattern}/${replacement}/" "$file_path" > "$tmp_file"
  mv "$tmp_file" "$file_path"
}

extract_plugin_header_version() {
  sed -n 's/^[[:space:]]*Version:[[:space:]]*//p' "bono-arm-api.php" | head -n 1
}

extract_plugin_constant_version() {
  sed -n "s/^define('BONO_ARM_API_VERSION', '\(.*\)');$/\1/p" "bono-arm-api.php" | head -n 1
}

extract_stable_tag_version() {
  sed -n 's/^Stable tag: //p' "readme.txt" | head -n 1
}

assert_versions_match() {
  local header_version
  local constant_version
  local stable_tag_version

  header_version="$(extract_plugin_header_version)"
  constant_version="$(extract_plugin_constant_version)"
  stable_tag_version="$(extract_stable_tag_version)"

  if [[ "$header_version" != "$VERSION" || "$constant_version" != "$VERSION" || "$stable_tag_version" != "$VERSION" ]]; then
    echo "Version mismatch detected after update:"
    echo "  Plugin header: ${header_version:-missing}"
    echo "  BONO_ARM_API_VERSION: ${constant_version:-missing}"
    echo "  Stable tag: ${stable_tag_version:-missing}"
    echo "Expected all three to equal $VERSION."
    exit 1
  fi
}

update_file "readme.txt" "^Stable tag: .*" "Stable tag: $VERSION"
update_file "bono-arm-api.php" "^Version: .*" "Version: $VERSION"
update_file "bono-arm-api.php" "^define('BONO_ARM_API_VERSION', '.*');$" "define('BONO_ARM_API_VERSION', '$VERSION');"

assert_versions_match

git add readme.txt bono-arm-api.php
git commit -m "Bump version to $VERSION"
git tag -a "$TAG" -m "Release $VERSION"
git push origin main
git push origin "$TAG"

cat <<MSG
Release prepared for $TAG.

GitHub Actions will now:
- build the WordPress plugin zip with ./build.sh
- create or update the GitHub Release for $TAG
- attach the generated versioned zip asset
MSG
