#!/usr/bin/env python3
"""Build the non-store BMaGlass Pay zips (Worker A + C families + automation).

Deterministic companion to scripts/build-store-zips.py (which builds the
11 store-module zips). This script builds:

- bmaglasspay-woocommerce-1.0.0.zip   (integrations/wordpress/bmaglass-pay/)
- bmaglasspay-sdk-typescript-1.0.0.zip (sdk/typescript — dist/ rebuilt first
  by scripts/build-zips.sh via `npm run build`)
- bmaglasspay-sdk-python-1.0.0.zip     (sdk/python)
- bmaglasspay-sdk-php-1.0.0.zip        (sdk/php)
- bmaglasspay-sdk-flutter-1.0.0.zip    (sdk/flutter)
- bmaglasspay-sdk-react-native-1.0.0.zip (sdk/react-native)
- bmaglasspay-embed-button-1.0.0.zip   (integrations/embed)
- bmaglasspay-automation-1.0.0.zip     (integrations/automation)

Every zip carries the repo LICENSE at its root where the family ships
one (all SDK/embed/automation zips); the WooCommerce zip is the plugin
folder verbatim (incl. its INSTALL.md), exactly as WordPress expects.

Reproducibility: same rules as build-store-zips.py — fixed entry
timestamps from SOURCE_DATE_EPOCH (default 2026-11-14T00:00:00Z),
fixed 0644 permissions, sorted entries, ZIP_DEFLATED level 9, no
directory entries. Rebuilding from the same tree is byte-identical.

Usage: python3 scripts/build-extra-zips.py [--dist DIR]
Appends to the dist dir (does NOT wipe it) — scripts/build-zips.sh
runs the store builder first, then this script, then the docs step.
"""
import argparse
import hashlib
import os
import time
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION = (ROOT / "VERSION").read_text().strip()

EPOCH = int(os.environ.get("SOURCE_DATE_EPOCH", "1794614400"))
DATE_TIME = time.gmtime(EPOCH)[:6]

BUCKET_URL = "https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/"

AUTOMATION_INSTALL = """# BMaGlass Pay x Zapier / Make - install

## 1. Unzip
Unzip this package anywhere and read `README.md` - it is the full
guide: which webhook events exist, the exact example payloads
(`examples/*.json`), and the step-by-step Zapier and Make recipes.

## 2. Pick a recipe
- Zapier: Webhooks by Zapier -> Catch Hook (trigger) or POST (action).
- Make: Webhooks -> Custom webhook (trigger) or HTTP -> Make a request
  (action), plus an optional scheduled GET poll as a reconcile fallback.

## 3. Paste your API key
Create an API key in your BMaGlass Pay dashboard (Business settings
or the API page) and paste it into the recipe's `X-Api-Key` header.
Use a key scoped to only what the recipe needs. A live key is
required for real writes; sandbox keys only pass connection checks
and settle through the simulate-payment endpoints.

## 4. Done
Register your Zapier/Make hook URL in BMaGlass Pay ->
Developers/Webhooks (or POST /webhooks with your key), save the
signing secret shown once, and send a test event. Automations that
act on money should re-read the truth from the API before acting.

Support: https://bmaglasspay.online/developers
"""


def _entry(arc: str) -> zipfile.ZipInfo:
    zi = zipfile.ZipInfo(arc, DATE_TIME)
    zi.compress_type = zipfile.ZIP_DEFLATED
    zi.external_attr = 0o644 << 16
    return zi


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def add_tree(zf: zipfile.ZipFile, src: Path, arc_prefix: str = "") -> list[str]:
    """Add a directory tree, sorted, skipping __pycache__ / *.pyc."""
    added = []
    for p in sorted(src.rglob("*")):
        if "__pycache__" in p.parts or p.suffix == ".pyc":
            continue
        if p.is_file():
            arc = (arc_prefix + "/" + p.relative_to(src).as_posix()).lstrip("/")
            zf.writestr(_entry(arc), p.read_bytes())
            added.append(arc)
    return added


def add_files(zf: zipfile.ZipFile, src: Path, rels: list[str],
              arc_prefix: str = "") -> list[str]:
    """Add an explicit, ordered file list (missing files are an error)."""
    added = []
    for rel in rels:
        p = src / rel
        assert p.is_file(), f"missing file for zip: {p}"
        arc = (arc_prefix + "/" + rel).lstrip("/")
        zf.writestr(_entry(arc), p.read_bytes())
        added.append(arc)
    return added


def build_zip(dist: Path, name: str, build) -> dict:
    path = dist / name
    if path.exists():
        path.unlink()
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        files = build(zf)
    return {"name": name, "path": path, "size": path.stat().st_size,
            "sha256": sha256(path), "files": sorted(files)}


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dist", default=str(ROOT / "dist"))
    args = ap.parse_args()
    dist = Path(args.dist)
    dist.mkdir(parents=True, exist_ok=True)
    lic = ROOT / "LICENSE"
    results = []

    # --- WooCommerce: plugin folder verbatim under bmaglass-pay/ ---------
    wp = ROOT / "integrations" / "wordpress" / "bmaglass-pay"

    def build_wp(zf, wp=wp):
        return add_tree(zf, wp, "bmaglass-pay")

    results.append(build_zip(
        dist, f"bmaglasspay-woocommerce-{VERSION}.zip", build_wp))

    # --- TypeScript SDK ---------------------------------------------------
    ts = ROOT / "sdk" / "typescript"

    def build_ts(zf, ts=ts):
        files = add_files(zf, ts, [
            "src/index.ts", "dist/index.js", "dist/index.d.ts",
            "package.json", "package-lock.json", "tsconfig.json",
            "README.md",
        ])
        files.append(add_files(zf, ROOT, ["LICENSE"])[0])
        return files

    results.append(build_zip(
        dist, f"bmaglasspay-sdk-typescript-{VERSION}.zip", build_ts))

    # --- Python SDK -------------------------------------------------------
    py = ROOT / "sdk" / "python"

    def build_py(zf, py=py):
        files = add_files(zf, py, [
            "bmaglass_sdk/__init__.py", "pyproject.toml", "README.md",
        ])
        files.append(add_files(zf, ROOT, ["LICENSE"])[0])
        return files

    results.append(build_zip(
        dist, f"bmaglasspay-sdk-python-{VERSION}.zip", build_py))

    # --- PHP SDK ----------------------------------------------------------
    php = ROOT / "sdk" / "php"

    def build_php(zf, php=php):
        files = add_files(zf, php, [
            "src/Client.php", "src/ApiException.php",
            "tests/run-tests.php", "composer.json", "README.md",
        ])
        files.append(add_files(zf, ROOT, ["LICENSE"])[0])
        return files

    results.append(build_zip(
        dist, f"bmaglasspay-sdk-php-{VERSION}.zip", build_php))

    # --- Flutter SDK ------------------------------------------------------
    fl = ROOT / "sdk" / "flutter"

    def build_fl(zf, fl=fl):
        files = add_files(zf, fl, [
            "lib/bmaglass_sdk.dart", "lib/src/client.dart",
            "lib/src/errors.dart", "lib/src/models.dart",
            "lib/src/webhook.dart", "test/bmaglass_sdk_test.dart",
            "pubspec.yaml", "README.md",
        ])
        files.append(add_files(zf, ROOT, ["LICENSE"])[0])
        return files

    results.append(build_zip(
        dist, f"bmaglasspay-sdk-flutter-{VERSION}.zip", build_fl))

    # --- React Native SDK -------------------------------------------------
    rn = ROOT / "sdk" / "react-native"

    def build_rn(zf, rn=rn):
        files = add_files(zf, rn, [
            "src/index.ts", "src/sha256.ts", "test/run-tests.mjs",
            "package.json", "tsconfig.json", "README.md",
        ])
        files.append(add_files(zf, ROOT, ["LICENSE"])[0])
        return files

    results.append(build_zip(
        dist, f"bmaglasspay-sdk-react-native-{VERSION}.zip", build_rn))

    # --- Embed button -----------------------------------------------------
    em = ROOT / "integrations" / "embed"

    def build_em(zf, em=em):
        files = add_files(zf, em, [
            "bmaglass-pay-button.js", "demo.html", "README.md",
        ])
        files.append(add_files(zf, ROOT, ["LICENSE"])[0])
        return files

    results.append(build_zip(
        dist, f"bmaglasspay-embed-button-{VERSION}.zip", build_em))

    # --- Automation (Zapier / Make) ---------------------------------------
    au = ROOT / "integrations" / "automation"

    def build_au(zf, au=au):
        files = add_tree(zf, au, "")
        files.append(add_files(zf, ROOT, ["LICENSE"])[0])
        zf.writestr(_entry("INSTALL/README.md"),
                    AUTOMATION_INSTALL.encode())
        files.append("INSTALL/README.md")
        return files

    results.append(build_zip(
        dist, f"bmaglasspay-automation-{VERSION}.zip", build_au))

    for r in results:
        print(f"{r['name']}  {r['size']} bytes  sha256:{r['sha256'][:16]}…  "
              f"{len(r['files'])} files")
    print(f"Wrote {len(results)} zips to {dist}")


if __name__ == "__main__":
    main()
