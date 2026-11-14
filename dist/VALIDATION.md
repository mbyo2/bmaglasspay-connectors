# bmaglasspay-woocommerce-1.0.0.zip — validation record

- Built from: `integrations/wordpress/bmaglass-pay/` at origin/main tip
  `ee69b04` (branch `bmg-zips-wp`), plus new `INSTALL.md` in the plugin folder.
- Zip: `dist/bmaglasspay-woocommerce-1.0.0.zip`
- Size: 30,475 bytes
- SHA-256: 70ac11f99f585f396f6cddf2910d7475ce097be748d0a7d8fca884448660acd1

## Contents (single top-level folder `bmaglass-pay/`, 11 files)

- bmaglass-pay/INSTALL.md (new install guide)
- bmaglass-pay/bmaglass-pay.php (Plugin Name: BMaGlass Pay for WooCommerce, Version 1.2.0)
- bmaglass-pay/readme.txt
- bmaglass-pay/uninstall.php
- bmaglass-pay/includes/class-bmaglass-api-client.php
- bmaglass-pay/includes/class-bmaglass-blocks-integration.php
- bmaglass-pay/includes/class-bmaglass-momo-blocks-integration.php
- bmaglass-pay/includes/class-bmaglass-reconciler.php
- bmaglass-pay/includes/class-wc-gateway-bmaglass.php
- bmaglass-pay/includes/class-wc-gateway-bmaglass-momo.php
- bmaglass-pay/assets/js/blocks-integration.js
- bmaglass-pay/assets/js/momo-blocks-integration.js

No test/dev files inside the plugin folder; nothing excluded that is needed.

## Validation (run against the UNZIPPED zip contents, not the repo source)

- `unzip -t`: no errors.
- Single top-level folder `bmaglass-pay/`; main file `bmaglass-pay/bmaglass-pay.php`
  at folder root with valid plugin header (Name, Version 1.2.0, Text Domain
  bmaglass-pay, Requires WP 6.0 / PHP 7.4 / WC 7.0, WC tested up to 11.2).
  Installable via WP Admin → Plugins → Add New → Upload.
- `php -l`: all 8 PHP files clean (PHP 8.3.6 CLI).
- `node --check`: both JS files clean.
- Secrets scan (bmp_live_/bmp_test_ keys, sk_live, hardcoded passwords): no hits.
- Unzipped tree diffed byte-identical to the repo plugin folder (incl. INSTALL.md).
- Mocked-WP simulation suites run against the unzipped code
  (tests copied unchanged into a temp layout mirroring integrations/wordpress/):
  - run-client-tests.php: 38 PASS / 0 FAIL
  - run-blocks-tests.php: 30 PASS / 0 FAIL
  - run-reconciler-tests.php: 18 PASS / 0 FAIL
  - Total: 86 PASS / 0 FAIL (matches the repo-source baseline exactly).

## Observation (non-blocking, no code changed)

- `readme.txt` says `Stable tag: 1.1.1` while `bmaglass-pay.php` says
  `Version: 1.2.0`. Not zip-breaking (WordPress reads the header, not the
  stable tag, for an uploaded zip), so left untouched per the no-functional-
  change fence. Recommend bumping the stable tag to 1.2.0 in a follow-up.
