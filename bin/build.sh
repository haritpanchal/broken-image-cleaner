#!/usr/bin/env bash
#
# Build the distribution zip — the thing that actually gets submitted and
# reviewed. Development files listed in .distignore are left out, so what the
# reviewer runs Plugin Check against is what this produces, not the working
# directory.
#
# Usage: bin/build.sh

set -euo pipefail

SLUG="broken-image-cleaner"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="${ROOT}/build"

rm -rf "${BUILD}"
mkdir -p "${BUILD}/${SLUG}"

# rsync skips blank lines and lines starting with # in an exclude file, so
# .distignore can stay commented.
rsync -a \
	--exclude-from="${ROOT}/.distignore" \
	--exclude="build" \
	--exclude="bin" \
	"${ROOT}/" "${BUILD}/${SLUG}/"

( cd "${BUILD}" && zip -rq "${SLUG}.zip" "${SLUG}" )

echo "Built ${BUILD}/${SLUG}.zip"
echo
echo "Contents:"
( cd "${BUILD}/${SLUG}" && find . -type f | sort | sed 's|^\./|  |' )

# Leave only the zip behind. An unpacked copy sitting inside the plugin
# directory gets treated as part of the plugin by anything that scans it —
# Plugin Check included — which makes the results meaningless.
rm -rf "${BUILD:?}/${SLUG}"
