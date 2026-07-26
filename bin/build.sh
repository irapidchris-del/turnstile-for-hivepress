#!/usr/bin/env bash
#
# Build distributable ZIPs for Turnstile for HivePress.
#
#   dist/turnstile-for-hivepress.zip           -> attach as the GitHub release
#                                                 asset (fixed name powers the
#                                                 "always latest" download link)
#   dist/turnstile-for-hivepress-<version>.zip -> identical contents, version in
#                                                 the filename, for your records
#
# Both contain a top-level "turnstile-for-hivepress/" folder (no version in the
# folder name) so WordPress installs into the correct directory with no
# "destination folder already exists" / slug-mismatch warnings.
#
# Usage:  bash bin/build.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="turnstile-for-hivepress"
SRC="$ROOT/$SLUG"
DIST="$ROOT/dist"
MAIN="$SRC/$SLUG.php"

if [ ! -f "$MAIN" ]; then
	echo "error: cannot find $MAIN" >&2
	exit 1
fi

# Read "Version:" from the plugin header.
VERSION="$(grep -m1 -oE 'Version:[[:space:]]*[0-9][0-9.]*' "$MAIN" | grep -oE '[0-9][0-9.]*')"
if [ -z "$VERSION" ]; then
	echo "error: could not read Version from plugin header" >&2
	exit 1
fi

# Runtime files that ship to users. Dev files (bin/, dist/, RELEASING.md,
# .git, .github) live at the repo root and are excluded by construction.
INCLUDE=( "$SLUG.php" uninstall.php readme.txt README.md LICENSE.txt inc js lib languages )

echo "Building $SLUG v$VERSION ..."
rm -rf "$DIST/$SLUG"
mkdir -p "$DIST/$SLUG"

for item in "${INCLUDE[@]}"; do
	if [ -e "$SRC/$item" ]; then
		cp -R "$SRC/$item" "$DIST/$SLUG/"
	fi
done

# Scrub editor/OS cruft that may have crept in.
find "$DIST/$SLUG" -name '.DS_Store' -delete 2>/dev/null || true
find "$DIST/$SLUG" -name 'Thumbs.db' -delete 2>/dev/null || true

cd "$DIST"
rm -f "$SLUG.zip" "$SLUG-$VERSION.zip"
zip -rqX "$SLUG.zip" "$SLUG"
cp "$SLUG.zip" "$SLUG-$VERSION.zip"
rm -rf "$DIST/$SLUG"

echo
echo "Done. Top-level folder inside both zips: $SLUG/"
echo "  dist/$SLUG.zip            <- attach to the GitHub release (asset name is fixed)"
echo "  dist/$SLUG-$VERSION.zip   <- internal copy, v$VERSION"
