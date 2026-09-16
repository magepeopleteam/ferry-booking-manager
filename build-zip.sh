#!/usr/bin/env bash
#
# Builds a clean, installable production zip of the plugin.
#
# The working tree also contains the Node.js source projects used to produce
# the pre-built bundles in assets/ (apps/admin, apps/booking - node_modules,
# .next cache, etc.) and dev-only Composer tooling (vendor/: phpunit, phpcs).
# None of that is needed on a live site - WordPress only ever loads files
# from assets/, src/, templates/, and languages/. This script copies just the
# runtime files into a staging directory and zips that.
#
# Usage: ./build-zip.sh [output-dir]

set -euo pipefail

PLUGIN_SLUG="ferry-booking-manager"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="${1:-/tmp}"
STAGE_DIR="$(mktemp -d)/${PLUGIN_SLUG}"
ZIP_PATH="${OUT_DIR}/${PLUGIN_SLUG}.zip"

mkdir -p "${STAGE_DIR}"

rsync -a "${PLUGIN_DIR}/" "${STAGE_DIR}/" \
	--exclude 'apps/' \
	--exclude 'vendor/' \
	--exclude 'tests/' \
	--exclude 'docs/' \
	--exclude '.git/' \
	--exclude '.gitignore' \
	--exclude '.distignore' \
	--exclude 'node_modules/' \
	--exclude '.next/' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'phpcs.xml' \
	--exclude 'phpunit.xml' \
	--exclude 'build-zip.sh' \
	--exclude '*.png' \
	--exclude '*.log'

rm -f "${ZIP_PATH}"
( cd "$(dirname "${STAGE_DIR}")" && zip -rq "${ZIP_PATH}" "${PLUGIN_SLUG}" )
rm -rf "$(dirname "${STAGE_DIR}")"

echo "Built: ${ZIP_PATH} ($(du -h "${ZIP_PATH}" | cut -f1))"
