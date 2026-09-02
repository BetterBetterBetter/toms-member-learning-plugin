#!/usr/bin/env bash

set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
slug='member-library-plugin'
output_dir="$repo_dir/build"
stage_dir="$(mktemp -d)"
trap 'rm -rf "$stage_dir"' EXIT

header_version="$(sed -nE 's/^ \* Version: ([0-9.]+)$/\1/p' "$repo_dir/member-library-plugin.php")"
constant_version="$(sed -nE "s/^define\('MEMBER_LIBRARY_PLUGIN_VERSION', '([0-9.]+)'\);$/\1/p" "$repo_dir/member-library-plugin.php")"
if [[ -z "$header_version" || "$header_version" != "$constant_version" ]]; then
  echo 'Plugin version declarations do not match.' >&2
  exit 1
fi

mkdir -p "$output_dir" "$stage_dir/$slug"
rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='build' \
  --exclude='tests' \
  --exclude='tools' \
  --exclude='*.md' \
  "$repo_dir/" "$stage_dir/$slug/"

rm -f "$output_dir/$slug.zip"
(cd "$stage_dir" && zip -rq "$output_dir/$slug.zip" "$slug")
printf '%s\n' "$output_dir/$slug.zip"
