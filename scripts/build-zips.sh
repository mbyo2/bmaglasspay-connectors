#!/usr/bin/env bash
# Build ALL BMaGlass Pay downloadable zips reproducibly (v1.0.0 release).
#
#   ./scripts/build-zips.sh            # builds dist/*.zip + docs
#   SOURCE_DATE_EPOCH=<epoch> ./scripts/build-zips.sh
#
# Reproducibility: every zip entry carries the same fixed timestamp
# (SOURCE_DATE_EPOCH, default 2026-11-14T00:00:00Z = 1794614400) and
# fixed 0644 permissions, entries are sorted, compression is
# ZIP_DEFLATED level 9, no directory entries. Rebuilding from the
# same tree is byte-identical — verify with:
#   ./scripts/build-zips.sh && sha256sum dist/*.zip
#   (run twice; the hashes must not move).
#
# What it builds (19 zips, version from the VERSION file at repo root):
#   - 1 WooCommerce plugin zip (integrations/wordpress/bmaglass-pay/)
#   - 11 store-module zips — Odoo 16.0-20.0, Magento 2, PrestaShop
#     1.7/8/9, OpenCart 3/4 (scripts/build-store-zips.py)
#   - 6 SDK/embed zips — TypeScript, Python, PHP, Flutter, React
#     Native, embed button (scripts/build-extra-zips.py)
#   - 1 automation zip — Zapier / Make recipes (build-extra-zips.py)
#
# Steps: rebuild the TypeScript SDK dist/ fresh, build the store zips,
# build the remaining zips, then regenerate dist/MANIFEST.md and
# DOWNLOADS.md (scripts/make-downloads-docs.py). Validation is a
# separate step: ./scripts/validate-all-zips.py
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-1794614400}"
VERSION="$(cat VERSION)"
echo "Building BMaGlass Pay zips v$VERSION (SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH)"

# 1. TypeScript SDK dist/ rebuilt fresh from current source (gitignored).
echo "--- TypeScript SDK: npm ci + build"
(cd sdk/typescript && npm ci --no-audit --no-fund >/dev/null && npm run build)

# 2. Store-module zips (wipes dist/ and rebuilds it).
echo "--- Store zips"
python3 scripts/build-store-zips.py

# 3. WooCommerce + SDK/embed + automation zips (appends to dist/).
echo "--- Extra zips (WooCommerce, SDKs, embed, automation)"
python3 scripts/build-extra-zips.py

# 4. Docs from the built zips (no placeholder hashes — real values only).
echo "--- Docs"
python3 scripts/make-downloads-docs.py

echo "Done. 19 zips in dist/ — run ./scripts/validate-all-zips.py next."
