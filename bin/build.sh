#!/usr/bin/env bash
#
# Build a WordPress.org-ready distribution ZIP of the plugin.
#
# Stages the runtime files (excluding everything in .distignore) into a temp
# folder, then writes <slug>.zip into the parent wp-content/plugins directory.
# The ZIP unpacks to a single `rapls-sitemap/` folder.
#
#   bin/build.sh [output directory]
#
# The output directory defaults to the parent wp-content/plugins folder. It is
# an argument so the build can be checked without writing a ZIP next to a live
# WordPress install — see tests/smoke-tooling.php.
#
set -euo pipefail

SLUG="rapls-sitemap"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$(cd "$ROOT/.." && pwd)}"
ZIP="$OUT/$SLUG.zip"

mkdir -p "$OUT"

# Staged inside the output directory rather than in the system temp folder,
# which is not always writable — a sandboxed or hardened environment will refuse
# mktemp while happily writing where it was told to put the ZIP.
TMP="$OUT/.build-$$"
trap 'rm -rf "$TMP"' EXIT
STAGE="$TMP/$SLUG"
mkdir -p "$STAGE"

# Stage runtime files only. rsync honours .distignore (gitignore-style patterns).
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/." "$STAGE/"

rm -f "$ZIP"
( cd "$TMP" && zip -rqX "$ZIP" "$SLUG" )

echo "Built: $ZIP"
echo "Top-level contents:"
( cd "$STAGE" && find . -maxdepth 1 -mindepth 1 | sort | sed 's#^\./#  #' )
