#!/usr/bin/env bash
#
# Builds a clean, installable production zip of the plugin.
#
# The bundles in assets/ are compiled from the Node.js projects in apps/admin
# and apps/booking. Their source ships in the zip so the compiled code can be
# studied and rebuilt, but their dependency trees and build caches do not.
# Dev-only Composer tooling (vendor/: phpunit, phpcs) is left out too.
#
# Usage: ./build-zip.sh [output-dir]

set -euo pipefail

PLUGIN_SLUG="magepeople-ferry-booking-system"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="${1:-/tmp}"
STAGE_DIR="$(mktemp -d)/${PLUGIN_SLUG}"
ZIP_PATH="${OUT_DIR}/${PLUGIN_SLUG}.zip"

mkdir -p "${STAGE_DIR}"

rsync -a "${PLUGIN_DIR}/" "${STAGE_DIR}/" \
	--exclude 'apps/*/node_modules/' \
	--exclude 'apps/*/.next/' \
	--exclude 'apps/*/out/' \
	--exclude 'apps/*/next-env.d.ts' \
	--exclude '*.tsbuildinfo' \
	--exclude 'assets/admin/app/_next/mpfbs/' \
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
