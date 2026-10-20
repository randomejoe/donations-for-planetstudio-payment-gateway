#!/bin/sh
# Build the release zip from the last commit, not from the working tree, so
# the zip is always something that can be traced back to a commit. Files
# marked export-ignore in .gitattributes (tests, tools) are left out.
#
# Run from anywhere: sh tools/build-zip.sh
set -eu

cd "$(dirname "$0")/.."

slug=donations-for-planetstudio-payment-gateway
version=$(sed -n 's/^ \* Version: *//p' "$slug.php")
zip="dist/$slug-$version.zip"

if ! git diff --quiet HEAD --; then
	echo "warning: uncommitted changes are not in the zip" >&2
fi

mkdir -p dist
git archive --format=zip --prefix="$slug/" -o "$zip" HEAD
echo "$zip"
