#!/usr/bin/env python3
"""Build the BMaGlass Pay store-module zips (Worker B).

Layouts follow the repo's own READMEs / VERSIONS.md (the layout authority):
- Odoo <ver>: zip root = version folder contents (payment_bmaglass/ at
  root, plus the version README.md and tests/), installable via
  Odoo Apps -> Import Module.
- Magento: top-level folder BMaGlass/ (BMaGlass/Pay/...), copy into
  app/code/BMaGlass/Pay.
- PrestaShop <ver>: top-level folder bmaglasspay/, Module Manager upload.
- OpenCart <ver>: top-level folder upload/ (+ install.json for 3.x),
  Extensions -> Installer.

Every zip also carries INSTALL/README.md at its root: upload ->
install/activate -> paste API key from the BMaGlass dashboard -> done.
No secrets, no fee talk. Excludes __pycache__ / *.pyc.

Usage: python3 scripts/build-store-zips.py [--dist DIR]
Writes zips to dist/ and a dist/MANIFEST.md with path, size, SHA-256
and file list per zip.
"""
import argparse
import hashlib
import shutil
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION = "1.0.0"

ODOO_INSTALL = """# BMaGlass Pay for Odoo {ver} — install

## 1. Upload
In Odoo, go to **Apps → Import Module** and upload this zip
(or copy the `payment_bmaglass/` folder into your Odoo addons path
and update the apps list).

## 2. Install / activate
Install **Payment Provider: BMaGlass Pay** from the Apps list.

## 3. Paste your API key
Go to **Invoicing / Accounting → Configuration → Payment Providers →
BMaGlass Pay** and paste an API key created in your BMaGlass Pay
dashboard (Business settings or the API page).

Use **Test connection** to check the key, then **Register webhook**
so BMaGlass can confirm payments to your store.

## 4. Done
Customers pay on BMaGlass's hosted payment page from their BMaGlass
wallet. A live (production) API key is required to take payments;
test keys only pass the connection check. Only ZMW and USD orders
are supported — those are the currencies BMaGlass wallets hold.

Support: https://bmaglasspay.online/developers
"""

MAGENTO_INSTALL = """# BMaGlass Pay for Magento 2 (2.4.6–2.4.9) — install

## 1. Upload
Copy the `BMaGlass/` folder from this zip into your store's
`app/code/` directory, so the module lives at
`app/code/BMaGlass/Pay` (or install it through Magento's
Component Manager / upload path).

## 2. Install / activate
Run:
    bin/magento module:enable BMaGlass_Pay
    bin/magento setup:upgrade
    bin/magento cache:flush

## 3. Paste your API key
Go to **Stores → Configuration → Sales → Payment Methods →
BMaGlass Pay**: enable the method and paste an API key created in
your BMaGlass Pay dashboard (Business settings or the API page).
Register the webhook URL shown there in BMaGlass Pay and paste the
signing secret shown once.

## 4. Done
Customers pay on BMaGlass's hosted payment page from their BMaGlass
wallet. A live (production) API key is required at checkout; test
keys only pass connection checks. Only ZMW and USD store currencies
are supported — those are the currencies BMaGlass wallets hold.

Support: https://bmaglasspay.online/developers
"""

PRESTASHOP_INSTALL = """# BMaGlass Pay for PrestaShop {ver} — install

## 1. Upload
In your back office, go to **Module Manager → Upload a module**
and select this zip (or copy the `bmaglasspay/` folder into
your store's `modules/` directory).

## 2. Install / activate
Install **BMaGlass Pay** from the module list and open its
configuration page.

## 3. Paste your API key
Paste an API key created in your BMaGlass Pay dashboard (Business
settings or the API page). Register the webhook URL shown on the
configuration page in BMaGlass Pay and paste the signing secret
shown once. Use **Test connection** to check the key.

## 4. Done
Customers pay on BMaGlass's hosted payment page from their BMaGlass
wallet. A live (production) API key is required at checkout; test
keys only pass the connection check. Only ZMW and USD store
currencies are supported — those are the currencies BMaGlass
wallets hold.

Support: https://bmaglasspay.online/developers
"""

OPENCART_INSTALL = """# BMaGlass Pay for OpenCart {ver} — install

## 1. Upload
In your admin panel, go to **Extensions → Installer** and upload
this zip (or copy the contents of the `upload/` folder over your
OpenCart root manually).

## 2. Install / activate
Go to **Extensions → Extensions → Payments → BMaGlass Pay** and
click **Install**, then **Edit**.

## 3. Paste your API key
Enable the payment method and paste an API key created in your
BMaGlass Pay dashboard (Business settings or the API page). Use
**Test connection** to check the key, register the webhook URL
shown in BMaGlass Pay, paste the signing secret shown once, and
pick the order status for paid orders.

## 4. Done
Customers pay on BMaGlass's hosted payment page from their BMaGlass
wallet. A live (production) API key is required to take payments;
test keys only pass the connection check. Only ZMW and USD store
currencies are supported — those are the currencies BMaGlass
wallets hold.

Support: https://bmaglasspay.online/developers
"""


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def add_tree(zf: zipfile.ZipFile, src: Path, arc_prefix: str = "") -> list[str]:
    """Add a directory tree to the zip, skipping __pycache__ / *.pyc."""
    added = []
    for p in sorted(src.rglob("*")):
        if "__pycache__" in p.parts or p.suffix == ".pyc":
            continue
        if p.is_file():
            arc = (arc_prefix + "/" + p.relative_to(src).as_posix()).lstrip("/")
            zf.write(p, arc)
            added.append(arc)
    return added


def add_text(zf: zipfile.ZipFile, arc: str, text: str) -> str:
    zf.writestr(arc, text)
    return arc


def build_zip(dist: Path, name: str, build) -> dict:
    path = dist / name
    if path.exists():
        path.unlink()
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        files = build(zf)
    return {
        "name": name,
        "path": path,
        "size": path.stat().st_size,
        "sha256": sha256(path),
        "files": sorted(files),
    }


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dist", default=str(ROOT / "dist"))
    args = ap.parse_args()
    dist = Path(args.dist)
    if dist.exists():
        shutil.rmtree(dist)
    dist.mkdir(parents=True)

    results = []

    # --- Odoo: one zip per major version -------------------------------
    for ver in ("16.0", "17.0", "18.0", "19.0", "20.0"):
        src = ROOT / "integrations" / "odoo" / ver

        def build(zf, src=src, ver=ver):
            files = []
            files += add_tree(zf, src / "payment_bmaglass", "payment_bmaglass")
            files += add_tree(zf, src / "tests", "tests")
            files.append(add_text(zf, "README.md", (src / "README.md").read_text()))
            files.append(add_text(zf, "INSTALL/README.md",
                                  ODOO_INSTALL.format(ver=ver)))
            return files

        results.append(build_zip(
            dist, f"bmaglasspay-odoo-{ver}-{VERSION}.zip", build))

    # --- Magento: one zip ----------------------------------------------
    mag = ROOT / "integrations" / "magento"

    def build_mag(zf, mag=mag):
        files = add_tree(zf, mag / "BMaGlass", "BMaGlass")
        files.append(add_text(zf, "INSTALL/README.md", MAGENTO_INSTALL))
        return files

    results.append(build_zip(
        dist, f"bmaglasspay-magento-{VERSION}.zip", build_mag))

    # --- PrestaShop: one zip per major ---------------------------------
    for ver in ("1.7", "8", "9"):
        src = ROOT / "integrations" / "prestashop" / ver

        def build_ps(zf, src=src, ver=ver):
            files = add_tree(zf, src / "bmaglasspay", "bmaglasspay")
            files.append(add_text(zf, "INSTALL/README.md",
                                  PRESTASHOP_INSTALL.format(ver=ver)))
            return files

        results.append(build_zip(
            dist, f"bmaglasspay-prestashop-{ver}-{VERSION}.zip", build_ps))

    # --- OpenCart: one zip per major ------------------------------------
    for ver, fname in (("3", "3.x"), ("4", "4.x")):
        src = ROOT / "integrations" / "opencart" / fname

        def build_oc(zf, src=src, ver=ver):
            files = add_tree(zf, src / "upload", "upload")
            if (src / "install.json").exists():
                files.append(add_text(
                    zf, "install.json", (src / "install.json").read_text()))
            files.append(add_text(zf, "INSTALL/README.md",
                                  OPENCART_INSTALL.format(ver=ver)))
            return files

        results.append(build_zip(
            dist, f"bmaglasspay-opencart-{ver}-{VERSION}.zip", build_oc))

    # --- Manifest --------------------------------------------------------
    lines = ["# BMaGlass Pay store-module zips — manifest\n",
             "Built from the bmaglasspay-connectors repo (branch "
             "`bmg-zips-store`). Layouts follow the repo's own READMEs / "
             "VERSIONS.md. Every zip carries `INSTALL/README.md` at its "
             "root.\n"]
    for r in results:
        lines.append(f"## {r['name']}\n")
        lines.append(f"- Size: {r['size']} bytes")
        lines.append(f"- SHA-256: `{r['sha256']}`")
        lines.append(f"- Files ({len(r['files'])}):")
        for f in r["files"]:
            lines.append(f"  - `{f}`")
        lines.append("")
    (dist / "MANIFEST.md").write_text("\n".join(lines))
    for r in results:
        print(f"{r['name']}  {r['size']} bytes  sha256:{r['sha256'][:16]}…  "
              f"{len(r['files'])} files")
    print(f"Wrote {len(results)} zips + MANIFEST.md to {dist}")


if __name__ == "__main__":
    main()
