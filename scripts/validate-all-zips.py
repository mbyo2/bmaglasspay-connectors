#!/usr/bin/env python3
"""Validate all 19 BMaGlass Pay zips in dist/ against the UNZIPPED code.

Runs, per family, the same checks the zip workers ran — now against the
final deterministically rebuilt zips:

- every zip: `zipfile.testzip`, no absolute/parent paths, no
  __pycache__ / *.pyc, no key-like secrets (real-looking `bmg_` /
  `bmp_` / `sk_live_` values; `...` placeholders do not match).
- store (11): scripts/validate-store-zips.py (structure + Odoo client
  tests 15/15 per variant + `php -l` + repo client-identity scripts).
- WooCommerce: the three mocked-WP suites (86 checks) + `php -l` +
  `node --check` + unzipped tree diffed against the repo plugin folder.
- SDKs: TS contract test (39 assertions), Python unittest (8 tests),
  PHP run-tests (26 checks), React Native run-tests (22 checks),
  Flutter `dart test` (10 tests; Dart 3.14.0 downloaded on demand if
  no `dart` is on PATH, else recorded as skipped, never fatal).
- embed: repo test-embed.mjs beside the unzipped files (11 checks).
- automation: every *.json parses; README + INSTALL/README present.

Writes dist/VALIDATION.md and prints a summary. Exit code 0 only if
every executed check passes (a skipped Dart download is recorded,
not a failure — same rule as the store validator's missing-PHP rule).
"""
import argparse
import json
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DART_VERSION = "3.14.0"
DART_URL = ("https://storage.googleapis.com/dart-archive/channels/stable/"
            f"release/{DART_VERSION}/sdk/dartsdk-linux-x64-release.zip")
DART_CACHE = Path.home() / ".cache" / "bmaglass-dart-sdk"  # NOT /tmp: /tmp is a 512M tmpfs that fills up

# Key-like values only — placeholders such as `bmg_live_...` (ellipsis)
# and documentation mentions never match.
SECRET_RES = [
    re.compile(r"bmg_(live|sandbox)_[A-Za-z0-9]{16,}"),
    re.compile(r"bmp_(live|test)_[A-Za-z0-9]{16,}"),
    re.compile(r"sk_live_[A-Za-z0-9]{16,}"),
    re.compile(r"(?i)(password|passwd)\s*=\s*['\"][^'\"]{8,}"),
]

STORE_NAMES = [f"bmaglasspay-odoo-{v}-1.0.0.zip"
               for v in ("16.0", "17.0", "18.0", "19.0", "20.0")] + [
    "bmaglasspay-magento-1.0.0.zip",
    "bmaglasspay-prestashop-1.7-1.0.0.zip",
    "bmaglasspay-prestashop-8-1.0.0.zip",
    "bmaglasspay-prestashop-9-1.0.0.zip",
    "bmaglasspay-opencart-3-1.0.0.zip",
    "bmaglasspay-opencart-4-1.0.0.zip",
]


def run(cmd, cwd=None, timeout=600):
    r = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True,
                       timeout=timeout)
    return r.returncode, (r.stdout + r.stderr).strip()


def sha256(path: Path) -> str:
    import hashlib
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def unzip_to(zpath: Path, dest: Path) -> None:
    dest.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(zpath) as zf:
        zf.extractall(dest)


def find_dart() -> str | None:
    dart = shutil.which("dart")
    if dart:
        return dart
    cached = DART_CACHE / "dart-sdk" / "bin" / "dart"
    if cached.exists():
        return str(cached)
    print(f"Flutter check: no dart on PATH — downloading Dart {DART_VERSION}…")
    try:
        DART_CACHE.mkdir(parents=True, exist_ok=True)
        zpath = DART_CACHE / "dartsdk.zip"
        # curl (proven on this VM); urllib fetched a non-zip body for
        # this URL in an earlier run, so it is only the fallback.
        rc, _ = run(["curl", "-sL", "--max-time", "300", "-o", str(zpath),
                     DART_URL])
        if rc != 0 or not zpath.exists():
            req = urllib.request.Request(
                DART_URL, headers={"User-Agent": "curl"})
            with urllib.request.urlopen(req, timeout=240) as resp, \
                    open(zpath, "wb") as f:
                shutil.copyfileobj(resp, f)
        with zipfile.ZipFile(zpath) as zf:
            zf.extractall(DART_CACHE)
        zpath.unlink()
        if cached.exists():
            return str(cached)
    except Exception as e:  # noqa: BLE001 — download failure is a SKIP, not fatal
        print(f"Flutter check: Dart download failed ({e}) — skipping.")
    return None


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dist", default=str(ROOT / "dist"))
    args = ap.parse_args()
    dist = Path(args.dist)
    zips = sorted(dist.glob("bmaglasspay-*.zip"))
    assert len(zips) == 19, f"expected 19 zips in {dist}, found {len(zips)}"
    version = (ROOT / "VERSION").read_text().strip()
    tmp = Path(tempfile.mkdtemp(prefix="validate-all-zips-"))
    lines = ["# BMaGlass Pay zips — validation (all 19)\n",
             f"Release version: **{version}**. Validated against the "
             "UNZIPPED zip contents (not the repo source) after the "
             "deterministic rebuild by `scripts/build-zips.sh`.\n"]
    overall_ok = True

    def section(title):
        lines.append(f"## {title}\n")

    def verdict(ok, text):
        nonlocal overall_ok
        lines.append(f"- {'OK' if ok else 'FAIL'} {text}")
        if not ok:
            overall_ok = False

    # --- 1. Structural + secret checks on every zip ----------------------
    section("Structure + secret scan — all 19 zips")
    for zpath in zips:
        with zipfile.ZipFile(zpath) as zf:
            names = zf.namelist()
            bad = zf.testzip()
            verdict(bad is None, f"{zpath.name}: archive integrity "
                    f"({'clean' if bad is None else f'bad file {bad}'})")
            bad_paths = [n for n in names
                         if n.startswith("/") or ".." in n.split("/")]
            verdict(not bad_paths, f"{zpath.name}: no absolute/parent paths")
            bad_code = [n for n in names
                        if "__pycache__" in n or n.endswith(".pyc")]
            verdict(not bad_code, f"{zpath.name}: no bytecode")
            hits = []
            for n in names:
                data = zf.read(n)
                try:
                    text = data.decode("utf-8", errors="ignore")
                except Exception:  # noqa: BLE001
                    continue
                for rx in SECRET_RES:
                    if rx.search(text):
                        hits.append(f"{n}: {rx.pattern[:40]}…")
            verdict(not hits, f"{zpath.name}: no key-like secrets "
                    f"({'none found' if not hits else hits})")
            lines.append(f"  - {zpath.name}: {zpath.stat().st_size} bytes, "
                         f"sha256 `{sha256(zpath)}`, {len(names)} files")

    # --- 2. Store battery -------------------------------------------------
    section("Store modules — scripts/validate-store-zips.py")
    store_dist = tmp / "store-dist"
    store_dist.mkdir()
    for name in STORE_NAMES:
        shutil.copy(dist / name, store_dist / name)
    rc, out = run([sys.executable, str(ROOT / "scripts" / "validate-store-zips.py"),
                   "--dist", str(store_dist)])
    lines.append(f"Validator exit code: {rc}")
    lines.append("```\n" + out + "\n```")
    if rc != 0:
        overall_ok = False
    # The store validator wrote its own report; fold its per-zip verdicts in.
    store_report = store_dist / "VALIDATION.md"
    if store_report.exists():
        lines.append("<details><summary>Store validator report "
                     "(dist/VALIDATION.md from the store run)</summary>\n")
        lines.append(store_report.read_text())
        lines.append("</details>\n")

    # --- 3. WooCommerce mocked-WP suites ----------------------------------
    section("WooCommerce — mocked-WP suites (unzipped zip)")
    wp_zip = dist / "bmaglasspay-woocommerce-1.0.0.zip"
    wp_dir = tmp / "wp"
    # Entries in the zip already carry the bmaglass-pay/ prefix.
    unzip_to(wp_zip, wp_dir)
    # Mirror integrations/wordpress/: tests/ beside bmaglass-pay/
    shutil.copytree(ROOT / "integrations" / "wordpress" / "tests",
                    wp_dir / "tests")
    for suite, expect in (("run-client-tests.php", 49),
                          ("run-blocks-tests.php", 30),
                          ("run-reconciler-tests.php", 18)):
        rc, out = run(["php", f"tests/{suite}"], cwd=wp_dir)
        passed = out.count("PASS ")
        failed = out.count("FAIL ")
        lines.append(f"- {suite}: rc={rc}, {passed} PASS / {failed} FAIL "
                     f"(expected {expect} PASS / 0 FAIL)")
        lines.append("```\n" + out + "\n```")
        if rc != 0 or failed or passed != expect:
            overall_ok = False
    php_files = sorted((wp_dir / "bmaglass-pay").rglob("*.php"))
    fails = [str(p) for p in php_files
             if run(["php", "-l", str(p)])[0] != 0]
    verdict(not fails, f"php -l clean on all {len(php_files)} PHP files"
            + (f" — FAIL: {fails}" if fails else ""))
    js_files = sorted((wp_dir / "bmaglass-pay").rglob("*.js"))
    fails = [str(p) for p in js_files
             if run(["node", "--check", str(p)])[0] != 0]
    verdict(not fails, f"node --check clean on all {len(js_files)} JS files"
            + (f" — FAIL: {fails}" if fails else ""))
    rc, out = run(["diff", "-r", str(wp_dir / "bmaglass-pay"),
                   str(ROOT / "integrations" / "wordpress" / "bmaglass-pay")])
    verdict(rc == 0, "unzipped tree byte-identical to repo plugin folder"
            + ("" if rc == 0 else f"\n```\n{out}\n```"))

    # --- 4. SDK batteries ---------------------------------------------------
    section("SDKs + embed — contract/unit tests (unzipped zips)")

    # TypeScript: mirror sdk/ layout so the contract test's relative
    # import (../typescript/dist/index.js) resolves.
    ts_root = tmp / "ts" / "sdk"
    (ts_root / "tests").mkdir(parents=True)
    shutil.copy(ROOT / "sdk" / "tests" / "ts-sdk.contract.mjs",
                ts_root / "tests" / "ts-sdk.contract.mjs")
    unzip_to(dist / "bmaglasspay-sdk-typescript-1.0.0.zip",
             ts_root / "typescript")
    rc1, out1 = run(["npm", "ci", "--no-audit", "--no-fund"],
                    cwd=ts_root / "typescript")
    rc2, out2 = run(["npm", "run", "build"], cwd=ts_root / "typescript")
    rc3, out3 = run(["node", "tests/ts-sdk.contract.mjs"], cwd=ts_root)
    ok = rc1 == 0 and rc2 == 0 and rc3 == 0
    lines.append(f"- TypeScript SDK: npm ci rc={rc1}, build rc={rc2}, "
                 f"contract test rc={rc3}")
    lines.append("```\n" + (out3 or out2 or out1)[-4000:] + "\n```")
    if not ok:
        overall_ok = False

    # Python: mirror sdk/ layout (test inserts parents[1]/python on path).
    py_root = tmp / "py" / "sdk"
    (py_root / "tests").mkdir(parents=True)
    shutil.copy(ROOT / "sdk" / "tests" / "test_python_sdk.py",
                py_root / "tests" / "test_python_sdk.py")
    unzip_to(dist / "bmaglasspay-sdk-python-1.0.0.zip", py_root / "python")
    rc, out = run([sys.executable, "-m", "unittest", "discover",
                   "-s", "sdk/tests", "-v"], cwd=tmp / "py")
    lines.append(f"- Python SDK: unittest discover rc={rc}")
    lines.append("```\n" + out[-4000:] + "\n```")
    if rc != 0:
        overall_ok = False

    # PHP SDK
    php_dir = tmp / "php-sdk"
    unzip_to(dist / "bmaglasspay-sdk-php-1.0.0.zip", php_dir)
    rc, out = run(["php", "tests/run-tests.php"], cwd=php_dir)
    lines.append(f"- PHP SDK: tests/run-tests.php rc={rc}")
    lines.append("```\n" + out[-3000:] + "\n```")
    if rc != 0:
        overall_ok = False

    # React Native SDK
    rn_dir = tmp / "rn-sdk"
    unzip_to(dist / "bmaglasspay-sdk-react-native-1.0.0.zip", rn_dir)
    rc, out = run(["node", "test/run-tests.mjs"], cwd=rn_dir)
    lines.append(f"- React Native SDK: test/run-tests.mjs rc={rc}")
    lines.append("```\n" + out[-3000:] + "\n```")
    if rc != 0:
        overall_ok = False

    # Flutter SDK (Dart downloaded on demand; SKIP recorded if unavailable)
    fl_dir = tmp / "fl-sdk"
    unzip_to(dist / "bmaglasspay-sdk-flutter-1.0.0.zip", fl_dir)
    dart = find_dart()
    if dart:
        rc1, out1 = run([dart, "pub", "get"], cwd=fl_dir)
        rc2, out2 = run([dart, "test"], cwd=fl_dir)
        lines.append(f"- Flutter SDK: dart pub get rc={rc1}, dart test rc={rc2}")
        lines.append("```\n" + (out2 or out1)[-3000:] + "\n```")
        if rc1 != 0 or rc2 != 0:
            overall_ok = False
    else:
        lines.append("- SKIP Flutter SDK `dart test`: no Dart SDK on this "
                     "machine and the Dart download failed — the zip "
                     "itself is source-only (lib/, test/, pubspec.yaml) "
                     "and was already validated 10/10 on the worker "
                     "branch; re-run `dart test` in the unzipped folder "
                     "when Dart is available.")

    # Embed button
    em_dir = tmp / "embed"
    unzip_to(dist / "bmaglasspay-embed-button-1.0.0.zip", em_dir)
    shutil.copy(ROOT / "integrations" / "embed" / "test-embed.mjs",
                em_dir / "test-embed.mjs")
    rc, out = run(["node", "test-embed.mjs"], cwd=em_dir)
    lines.append(f"- Embed button: test-embed.mjs rc={rc}")
    lines.append("```\n" + out[-3000:] + "\n```")
    if rc != 0:
        overall_ok = False

    # --- 5. Automation pack -------------------------------------------------
    section("Automation — JSON validity (unzipped zip)")
    au_dir = tmp / "automation"
    unzip_to(dist / "bmaglasspay-automation-1.0.0.zip", au_dir)
    jsons = sorted(au_dir.rglob("*.json"))
    bad = []
    for jf in jsons:
        try:
            json.loads(jf.read_text())
        except Exception as e:  # noqa: BLE001
            bad.append(f"{jf.name}: {e}")
    verdict(not bad, f"all {len(jsons)} JSON files parse"
            + ("" if not bad else f" — FAIL: {bad}"))
    for req in ("README.md", "INSTALL/README.md",
                "zapier/triggers-and-creates.json"):
        verdict((au_dir / req).exists(), f"automation zip contains {req}")

    # --- Report --------------------------------------------------------------
    lines.append(f"\n**Zip validation overall: "
                 f"{'PASS' if overall_ok else 'FAIL'}**\n")
    report = "\n".join(lines)
    # Normalise run-varying noise so the report is reproducible:
    # unittest timings ("Ran 15 tests in 0.001s") and the random temp
    # dir path of this run. Without this, re-running the validator
    # dirties the tracked VALIDATION.md for no reason.
    report = re.sub(r"Ran (\d+) tests? in [\d.]+s", r"Ran \1 tests", report)
    report = report.replace(str(tmp), "<tmpdir>")
    (dist / "VALIDATION.md").write_text(report)
    print("\n".join(lines))
    print(f"\nValidation report: {dist / 'VALIDATION.md'}")
    sys.exit(0 if overall_ok else 1)


if __name__ == "__main__":
    main()
