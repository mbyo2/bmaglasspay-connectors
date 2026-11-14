# Store-module zips — validation

PHP interpreter: `/usr/bin/php`

## bmaglasspay-magento-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 6 expected paths present
- Files in zip: 16
- OK php -l clean on all 9 PHP files

## bmaglasspay-odoo-16.0-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 9 expected paths present
- Files in zip: 17
- OK Odoo client tests (unzipped code):
```
Ran 15 tests in 0.001s

OK
```

## bmaglasspay-odoo-17.0-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 9 expected paths present
- Files in zip: 18
- OK Odoo client tests (unzipped code):
```
Ran 15 tests in 0.001s

OK
```

## bmaglasspay-odoo-18.0-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 9 expected paths present
- Files in zip: 18
- OK Odoo client tests (unzipped code):
```
Ran 15 tests in 0.001s

OK
```

## bmaglasspay-odoo-19.0-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 9 expected paths present
- Files in zip: 18
- OK Odoo client tests (unzipped code):
```
Ran 15 tests in 0.001s

OK
```

## bmaglasspay-odoo-20.0-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 9 expected paths present
- Files in zip: 18
- OK Odoo client tests (unzipped code):
```
Ran 15 tests in 0.001s

OK
```

## bmaglasspay-opencart-3-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 3 expected paths present + install.json
- Files in zip: 11
- OK php -l clean on all 7 PHP files

## bmaglasspay-opencart-4-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 3 expected paths present
- Files in zip: 10
- OK php -l clean on all 7 PHP files

## bmaglasspay-prestashop-1.7-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 6 expected paths present
- Files in zip: 8
- OK php -l clean on all 5 PHP files

## bmaglasspay-prestashop-8-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 6 expected paths present
- Files in zip: 8
- OK php -l clean on all 5 PHP files

## bmaglasspay-prestashop-9-1.0.0.zip

- OK no absolute or parent paths in archive
- OK no __pycache__ / *.pyc
- OK structure: all 6 expected paths present
- Files in zip: 9
- OK php -l clean on all 5 PHP files

## Repo-level client checks (source tree)

### integrations/odoo/check-clients.sh — OK (rc=0)
```
OK   odoo 16.0 client identical
OK   odoo 17.0 client identical
OK   odoo 18.0 client identical
OK   odoo 19.0 client identical
OK   odoo 20.0 client identical
```

### integrations/check-vendored-clients.sh — FAIL (rc=1)
```
FAIL drift     integrations/prestashop/1.7/bmaglasspay/classes/sdk/Client.php differs from sdk/php/src/Client.php
FAIL drift     integrations/prestashop/8/bmaglasspay/classes/sdk/Client.php differs from sdk/php/src/Client.php
FAIL drift     integrations/prestashop/9/bmaglasspay/classes/sdk/Client.php differs from sdk/php/src/Client.php
FAIL drift     integrations/opencart/4.x/upload/system/library/bmaglass/Client.php differs from sdk/php/src/Client.php
FAIL drift     integrations/opencart/3.x/upload/system/library/bmaglass/Client.php differs from sdk/php/src/Client.php
FAIL drift     integrations/magento/BMaGlass/Pay/Model/Api/Client.php differs from sdk/php/src/Client.php beyond the namespace
OK   verbatim  integrations/prestashop/1.7/bmaglasspay/classes/sdk/ApiException.php
OK   verbatim  integrations/prestashop/8/bmaglasspay/classes/sdk/ApiException.php
OK   verbatim  integrations/prestashop/9/bmaglasspay/classes/sdk/ApiException.php
OK   verbatim  integrations/opencart/4.x/upload/system/library/bmaglass/ApiException.php
OK   verbatim  integrations/opencart/3.x/upload/system/library/bmaglass/ApiException.php
OK   namespaced integrations/magento/BMaGlass/Pay/Model/Api/ApiException.php (equal modulo namespace)
```

Note (pre-existing drift on origin/main, NOT zip-breaking, NOT introduced by this work — no functional code was modified): every vendored `Client.php` differs from `sdk/php/src/Client.php` by exactly the 5-line preamble added to the SDK copy in commit ee69b04 (2026-11-13) (`require_once __DIR__ . '/ApiException.php';` for standalone use) which was never synced into the six vendored copies. Verified by diff: no other line differs in any of the six copies (Magento modulo its namespace). Runtime impact is nil in the packaged context — every platform loads `ApiException.php` explicitly before `Client.php` (PrestaShop main module + front controllers, OpenCart controllers) or via Composer autoloading (Magento) — and `php -l` is clean on all vendored files. Minimal fix if the repo wants its own proof script green again: copy the same 5-line preamble into the six vendored `Client.php` files (or relax the script). Left unfixed here per the no-functional-code-change fence, since it is not a zip-breaking defect.


**Zip validation overall: PASS**

Repo-level checks are reported above for the record and do not change the zip verdict; see VALIDATION notes if one failed.
