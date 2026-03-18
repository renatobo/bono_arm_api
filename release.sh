#!/bin/bash

set -euo pipefail

VERSION="${1:-}"
PLUGIN_FILE="bono-arm-api.php"
README_FILE="readme.txt"
PROJECT_README_FILE="README.md"
RELEASE_SUPPORT_FILES=(
  "$README_FILE"
  "$PLUGIN_FILE"
  "$PROJECT_README_FILE"
  "build.sh"
  "release.sh"
  ".gitignore"
)

if [[ -z "$VERSION" ]]; then
  read -r -p "Enter new version (e.g. 1.2.1): " VERSION
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Invalid version format. Use semantic versioning: X.Y.Z"
  exit 1
fi

TAG="v$VERSION"

if git rev-parse "$TAG" >/dev/null 2>&1; then
  echo "Tag $TAG already exists."
  exit 1
fi

is_allowed_release_path() {
  local path="$1"
  local allowed

  if [[ "$path" == *.zip && "$path" != */* ]]; then
    return 0
  fi

  for allowed in "${RELEASE_SUPPORT_FILES[@]}"; do
    if [[ "$path" == "$allowed" ]]; then
      return 0
    fi
  done

  return 1
}

assert_releasable_worktree() {
  local changed_paths=()
  local unexpected_paths=()
  local path

  while IFS= read -r path; do
    if [[ -n "$path" ]]; then
      changed_paths+=("$path")
    fi
  done < <(
    {
      git diff --name-only
      git diff --cached --name-only
      git ls-files --others --exclude-standard
    } | sort -u
  )

  if [[ ${#changed_paths[@]} -eq 0 ]]; then
    return
  fi

  for path in "${changed_paths[@]}"; do
    if ! is_allowed_release_path "$path"; then
      unexpected_paths+=("$path")
    fi
  done

  if [[ ${#unexpected_paths[@]} -eq 0 ]]; then
    return
  fi

  echo "Working tree contains non-release changes:"
  printf '  %s\n' "${unexpected_paths[@]}"
  echo "Commit or stash those paths before running a release."
  exit 1
}

remove_tracked_release_archives() {
  local tracked_archives=()
  local path

  while IFS= read -r path; do
    if [[ -n "$path" && "$path" != */* ]]; then
      tracked_archives+=("$path")
    fi
  done < <(git ls-files -- '*.zip')

  if [[ ${#tracked_archives[@]} -eq 0 ]]; then
    return
  fi

  echo "Removing tracked release archives:"
  printf '  %s\n' "${tracked_archives[@]}"
  git rm -- "${tracked_archives[@]}"
}

stage_release_support_files() {
  local file

  for file in "${RELEASE_SUPPORT_FILES[@]}"; do
    if [[ -e "$file" ]]; then
      git add -- "$file"
    fi
  done
}

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
  sed -n 's/^[[:space:]]*Version:[[:space:]]*//p' "$PLUGIN_FILE" | head -n 1
}

extract_plugin_constant_version() {
  sed -n "s/^define('BONO_ARM_API_VERSION', '\(.*\)');$/\1/p" "$PLUGIN_FILE" | head -n 1
}

extract_stable_tag_version() {
  sed -n 's/^Stable tag: //p' "$README_FILE" | head -n 1
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

assert_releasable_worktree

remove_tracked_release_archives

update_file "$README_FILE" "^Stable tag: .*" "Stable tag: $VERSION"
update_file "$PLUGIN_FILE" "^Version: .*" "Version: $VERSION"
update_file "$PLUGIN_FILE" "^define('BONO_ARM_API_VERSION', '.*');$" "define('BONO_ARM_API_VERSION', '$VERSION');"
update_file "$PROJECT_README_FILE" "^Current version: `.*`$" "Current version: \`$VERSION\`"

assert_versions_match

stage_release_support_files
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
