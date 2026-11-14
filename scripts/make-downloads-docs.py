#!/usr/bin/env python3
"""Generate the download docs for the BMaGlass Pay zips (v1.0.0 release).

Reads every bmaglasspay-*.zip in dist/ (built by scripts/build-zips.sh)
and writes:

- dist/MANIFEST.md — one section per zip: size, SHA-256, file list.
- DOWNLOADS.md (repo root) — the public table: file, size, SHA-256,
  public download URL, install path. The site manifest
  (public/downloads.json in the site repo) is filled from the same
  zips by scripts/upload-zips.sh after upload.

Expects exactly 19 zips: 1 WooCommerce + 11 store modules + 6 SDK/embed
+ 1 automation. No placeholder hashes ever — every value here comes
from a zip that was just built.
"""
import argparse
import hashlib
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BUCKET_URL = "https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/"

# zip file -> install path shown to developers (no fee talk anywhere)
INSTALL_PATH = {
    "bmaglasspay-woocommerce-1.0.0.zip":
        "WordPress Admin → Plugins → Add New → Upload",
    "bmaglasspay-odoo-16.0-1.0.0.zip": "Odoo Apps → Import Module",
    "bmaglasspay-odoo-17.0-1.0.0.zip": "Odoo Apps → Import Module",
    "bmaglasspay-odoo-18.0-1.0.0.zip": "Odoo Apps → Import Module",
    "bmaglasspay-odoo-19.0-1.0.0.zip": "Odoo Apps → Import Module",
    "bmaglasspay-odoo-20.0-1.0.0.zip": "Odoo Apps → Import Module",
    "bmaglasspay-magento-1.0.0.zip":
        "Copy `BMaGlass/` into `app/code/` (Magento 2.4.6–2.4.9)",
    "bmaglasspay-prestashop-1.7-1.0.0.zip":
        "PrestaShop back office → Module Manager → Upload a module",
    "bmaglasspay-prestashop-8-1.0.0.zip":
        "PrestaShop back office → Module Manager → Upload a module",
    "bmaglasspay-prestashop-9-1.0.0.zip":
        "PrestaShop back office → Module Manager → Upload a module",
    "bmaglasspay-opencart-3-1.0.0.zip":
        "OpenCart admin → Extensions → Installer",
    "bmaglasspay-opencart-4-1.0.0.zip":
        "OpenCart admin → Extensions → Installer",
    "bmaglasspay-sdk-typescript-1.0.0.zip":
        "Drop in / `npm install` from the package in the zip (`@bmaglass/sdk`)",
    "bmaglasspay-sdk-python-1.0.0.zip":
        "Drop `bmaglass_sdk/` into your project (`bmaglass-sdk`)",
    "bmaglasspay-sdk-php-1.0.0.zip":
        "Drop `src/` into your project, or require via Composer (`bmaglass/bmaglass-sdk`)",
    "bmaglasspay-sdk-flutter-1.0.0.zip":
        "Drop into your Flutter / Dart project (`bmaglass_sdk`)",
    "bmaglasspay-sdk-react-native-1.0.0.zip":
        "Drop into your React Native / Expo project (`@bmaglass/sdk-react-native`)",
    "bmaglasspay-embed-button-1.0.0.zip":
        "Copy `bmaglass-pay-button.js` into any web page (see `demo.html`)",
    "bmaglasspay-automation-1.0.0.zip":
        "Unzip, read `README.md`, import the Zapier / Make recipe",
}


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dist", default=str(ROOT / "dist"))
    args = ap.parse_args()
    dist = Path(args.dist)
    zips = sorted(dist.glob("bmaglasspay-*.zip"))
    assert len(zips) == 19, f"expected 19 zips in {dist}, found {len(zips)}"
    version = (ROOT / "VERSION").read_text().strip()

    rows = []
    for zpath in zips:
        with zipfile.ZipFile(zpath) as zf:
            files = sorted(zf.namelist())
        rows.append({
            "name": zpath.name,
            "size": zpath.stat().st_size,
            "sha256": sha256(zpath),
            "files": files,
            "url": BUCKET_URL + zpath.name,
        })

    # --- dist/MANIFEST.md -------------------------------------------------
    lines = ["# BMaGlass Pay downloadable zips — manifest\n",
             f"Release version: **{version}** (see `VERSION` at the repo root). "
             "Built by `scripts/build-zips.sh` from a clean checkout; "
             "rebuilding from the same tree is byte-identical "
             "(fixed timestamps, see the build scripts).\n",
             "Component-internal versions stay as-is: the WooCommerce "
             "plugin header is 1.2.0, the SDKs are 1.0.0. The zip "
             f"release version is {version} for every file.\n",
             "Every zip carries install instructions: `INSTALL/README.md` "
             "at its root (store modules, automation) or the package "
             "README (SDKs, embed); the WooCommerce zip is the plugin "
             "folder verbatim, including its `INSTALL.md`.\n"]
    for r in rows:
        lines.append(f"## {r['name']}\n")
        lines.append(f"- Size: {r['size']} bytes")
        lines.append(f"- SHA-256: `{r['sha256']}`")
        lines.append(f"- Download: {r['url']}")
        lines.append(f"- Install: {INSTALL_PATH[r['name']]}")
        lines.append(f"- Files ({len(r['files'])}):")
        for f in r["files"]:
            lines.append(f"  - `{f}`")
        lines.append("")
    (dist / "MANIFEST.md").write_text("\n".join(lines))
    print(f"Wrote {dist / 'MANIFEST.md'}")

    # --- DOWNLOADS.md ------------------------------------------------------
    lines = ["# BMaGlass Pay — downloadable integration packages\n",
             f"Release version **{version}** (see `VERSION`). Every package "
             "is built from this repository by `scripts/build-zips.sh`; "
             "each one is the same code as in the repo, plus install "
             "instructions. Download a package, upload it to your store "
             "or drop it into your project, paste an API key created in "
             "your BMaGlass Pay dashboard, and you are done.\n",
             "Component-internal versions stay as-is: the WooCommerce "
             "plugin header is 1.2.0 and the SDKs are 1.0.0 — the zip "
             f"release version is {version} for every file.\n",
             "Shopify has no downloadable package: checkout payments are "
             "gated by Shopify's payments partner programme, so there is "
             "no BMaGlass checkout plugin — see "
             "`integrations/shopify/ASSESSMENT.md`. Payment links and the "
             "embed button work in Shopify pages and emails today.\n",
             "| File | Size (bytes) | SHA-256 | Download | Install path |",
             "|---|---|---|---|---|"]
    for r in rows:
        lines.append(f"| `{r['name']}` | {r['size']} | `{r['sha256']}` | "
                     f"[Download]({r['url']}) | {INSTALL_PATH[r['name']]} |")
    lines.append("")
    lines.append("Hashes are of the exact files served from the public "
                 "`downloads` bucket; rebuilds from the same tree are "
                 "byte-identical, so a re-downloaded zip hashes the same.")
    lines.append("")
    (ROOT / "DOWNLOADS.md").write_text("\n".join(lines))
    print(f"Wrote {ROOT / 'DOWNLOADS.md'} ({len(rows)} zips)")


if __name__ == "__main__":
    main()
