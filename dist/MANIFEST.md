# BMaGlass Pay downloadable zips — manifest

Release version: **1.0.0** (see `VERSION` at the repo root). Built by `scripts/build-zips.sh` from a clean checkout; rebuilding from the same tree is byte-identical (fixed timestamps, see the build scripts).

Component-internal versions stay as-is: the WooCommerce plugin header is 1.2.0, the SDKs are 1.0.0. The zip release version is 1.0.0 for every file.

Every zip carries install instructions: `INSTALL/README.md` at its root (store modules, automation) or the package README (SDKs, embed); the WooCommerce zip is the plugin folder verbatim, including its `INSTALL.md`.

## bmaglasspay-automation-1.0.0.zip

- Size: 7087 bytes
- SHA-256: `f00abf1be1b61fa6412d86d947fea9f8c0f148735beb688db134f81232fffe3a`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-automation-1.0.0.zip
- Install: Unzip, read `README.md`, import the Zapier / Make recipe
- Files (8):
  - `INSTALL/README.md`
  - `LICENSE`
  - `README.md`
  - `examples/payment-link-created.json`
  - `examples/payment-link-paid.json`
  - `examples/transaction-completed.json`
  - `examples/webhook-test.json`
  - `zapier/triggers-and-creates.json`

## bmaglasspay-embed-button-1.0.0.zip

- Size: 4798 bytes
- SHA-256: `449cff1d7845183ea74c4a4d3f9bafb17fc1e0129e18ebf39953c53e25d1bb79`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-embed-button-1.0.0.zip
- Install: Copy `bmaglass-pay-button.js` into any web page (see `demo.html`)
- Files (4):
  - `LICENSE`
  - `README.md`
  - `bmaglass-pay-button.js`
  - `demo.html`

## bmaglasspay-magento-1.0.0.zip

- Size: 17347 bytes
- SHA-256: `59474dd3a445d23f9fe5692dd86a08e2afb3356dbc409171f3e066fe8d08a421`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-magento-1.0.0.zip
- Install: Copy `BMaGlass/` into `app/code/` (Magento 2.4.6–2.4.9)
- Files (16):
  - `BMaGlass/Pay/Controller/Checkout/Redirect.php`
  - `BMaGlass/Pay/Controller/Webhook/Index.php`
  - `BMaGlass/Pay/Cron/Reconcile.php`
  - `BMaGlass/Pay/Model/Api/ApiException.php`
  - `BMaGlass/Pay/Model/Api/Client.php`
  - `BMaGlass/Pay/Model/Config.php`
  - `BMaGlass/Pay/Model/Method/BmaglassMethod.php`
  - `BMaGlass/Pay/Model/Reconciler.php`
  - `BMaGlass/Pay/composer.json`
  - `BMaGlass/Pay/etc/adminhtml/system.xml`
  - `BMaGlass/Pay/etc/config.xml`
  - `BMaGlass/Pay/etc/crontab.xml`
  - `BMaGlass/Pay/etc/frontend/routes.xml`
  - `BMaGlass/Pay/etc/module.xml`
  - `BMaGlass/Pay/registration.php`
  - `INSTALL/README.md`

## bmaglasspay-odoo-16.0-1.0.0.zip

- Size: 20573 bytes
- SHA-256: `344040efd92d7caef284134aeb14909314d98062269a743c60113a2947909fdb`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-odoo-16.0-1.0.0.zip
- Install: Odoo Apps → Import Module
- Files (17):
  - `INSTALL/README.md`
  - `README.md`
  - `payment_bmaglass/__init__.py`
  - `payment_bmaglass/__manifest__.py`
  - `payment_bmaglass/controllers/__init__.py`
  - `payment_bmaglass/controllers/main.py`
  - `payment_bmaglass/data/ir_cron.xml`
  - `payment_bmaglass/data/payment_provider_data.xml`
  - `payment_bmaglass/models/__init__.py`
  - `payment_bmaglass/models/bmaglass_api.py`
  - `payment_bmaglass/models/payment_provider.py`
  - `payment_bmaglass/models/payment_transaction.py`
  - `payment_bmaglass/security/ir.model.access.csv`
  - `payment_bmaglass/static/description/index.html`
  - `payment_bmaglass/views/payment_bmaglass_templates.xml`
  - `payment_bmaglass/views/payment_provider_views.xml`
  - `tests/test_bmaglass_client.py`

## bmaglasspay-odoo-17.0-1.0.0.zip

- Size: 21090 bytes
- SHA-256: `7f750ca3b002b202ee943d9971837dea8e1b35d0eafd1056a37a08df0d15cf0a`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-odoo-17.0-1.0.0.zip
- Install: Odoo Apps → Import Module
- Files (18):
  - `INSTALL/README.md`
  - `README.md`
  - `payment_bmaglass/__init__.py`
  - `payment_bmaglass/__manifest__.py`
  - `payment_bmaglass/controllers/__init__.py`
  - `payment_bmaglass/controllers/main.py`
  - `payment_bmaglass/data/ir_cron.xml`
  - `payment_bmaglass/data/payment_method_data.xml`
  - `payment_bmaglass/data/payment_provider_data.xml`
  - `payment_bmaglass/models/__init__.py`
  - `payment_bmaglass/models/bmaglass_api.py`
  - `payment_bmaglass/models/payment_provider.py`
  - `payment_bmaglass/models/payment_transaction.py`
  - `payment_bmaglass/security/ir.model.access.csv`
  - `payment_bmaglass/static/description/index.html`
  - `payment_bmaglass/views/payment_bmaglass_templates.xml`
  - `payment_bmaglass/views/payment_provider_views.xml`
  - `tests/test_bmaglass_client.py`

## bmaglasspay-odoo-18.0-1.0.0.zip

- Size: 21059 bytes
- SHA-256: `56f7f3291952574a35478d476f7991fe95aa0c9f0494bec07ac4388fec2bd01b`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-odoo-18.0-1.0.0.zip
- Install: Odoo Apps → Import Module
- Files (18):
  - `INSTALL/README.md`
  - `README.md`
  - `payment_bmaglass/__init__.py`
  - `payment_bmaglass/__manifest__.py`
  - `payment_bmaglass/controllers/__init__.py`
  - `payment_bmaglass/controllers/main.py`
  - `payment_bmaglass/data/ir_cron.xml`
  - `payment_bmaglass/data/payment_method_data.xml`
  - `payment_bmaglass/data/payment_provider_data.xml`
  - `payment_bmaglass/models/__init__.py`
  - `payment_bmaglass/models/bmaglass_api.py`
  - `payment_bmaglass/models/payment_provider.py`
  - `payment_bmaglass/models/payment_transaction.py`
  - `payment_bmaglass/security/ir.model.access.csv`
  - `payment_bmaglass/static/description/index.html`
  - `payment_bmaglass/views/payment_bmaglass_templates.xml`
  - `payment_bmaglass/views/payment_provider_views.xml`
  - `tests/test_bmaglass_client.py`

## bmaglasspay-odoo-19.0-1.0.0.zip

- Size: 21233 bytes
- SHA-256: `970e9839ec5892f40d01e0aaa9b414805f8167265ad1a50c5b7ad5ac6b666678`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-odoo-19.0-1.0.0.zip
- Install: Odoo Apps → Import Module
- Files (18):
  - `INSTALL/README.md`
  - `README.md`
  - `payment_bmaglass/__init__.py`
  - `payment_bmaglass/__manifest__.py`
  - `payment_bmaglass/controllers/__init__.py`
  - `payment_bmaglass/controllers/main.py`
  - `payment_bmaglass/data/ir_cron.xml`
  - `payment_bmaglass/data/payment_method_data.xml`
  - `payment_bmaglass/data/payment_provider_data.xml`
  - `payment_bmaglass/models/__init__.py`
  - `payment_bmaglass/models/bmaglass_api.py`
  - `payment_bmaglass/models/payment_provider.py`
  - `payment_bmaglass/models/payment_transaction.py`
  - `payment_bmaglass/security/ir.model.access.csv`
  - `payment_bmaglass/static/description/index.html`
  - `payment_bmaglass/views/payment_bmaglass_templates.xml`
  - `payment_bmaglass/views/payment_provider_views.xml`
  - `tests/test_bmaglass_client.py`

## bmaglasspay-odoo-20.0-1.0.0.zip

- Size: 23294 bytes
- SHA-256: `e09343627346d3e6b1cbe244f549b04dfb292e0bb1fa3b605bf86ddba0324a6c`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-odoo-20.0-1.0.0.zip
- Install: Odoo Apps → Import Module
- Files (18):
  - `INSTALL/README.md`
  - `README.md`
  - `payment_bmaglass/__init__.py`
  - `payment_bmaglass/__manifest__.py`
  - `payment_bmaglass/controllers/__init__.py`
  - `payment_bmaglass/controllers/main.py`
  - `payment_bmaglass/data/ir_cron.xml`
  - `payment_bmaglass/data/payment_method_data.xml`
  - `payment_bmaglass/data/payment_provider_data.xml`
  - `payment_bmaglass/models/__init__.py`
  - `payment_bmaglass/models/bmaglass_api.py`
  - `payment_bmaglass/models/payment_provider.py`
  - `payment_bmaglass/models/payment_transaction.py`
  - `payment_bmaglass/security/ir.access.csv`
  - `payment_bmaglass/static/description/index.html`
  - `payment_bmaglass/views/payment_bmaglass_templates.xml`
  - `payment_bmaglass/views/payment_provider_views.xml`
  - `tests/test_bmaglass_client.py`

## bmaglasspay-opencart-3-1.0.0.zip

- Size: 15067 bytes
- SHA-256: `04a3d2f46941fb5cf9fdb8c5b7d46f7195d29fdc026bc308440064655acfda63`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-opencart-3-1.0.0.zip
- Install: OpenCart admin → Extensions → Installer
- Files (11):
  - `INSTALL/README.md`
  - `install.json`
  - `upload/admin/controller/extension/payment/bmaglasspay.php`
  - `upload/admin/language/en-gb/extension/payment/bmaglasspay.php`
  - `upload/admin/view/template/extension/payment/bmaglasspay.twig`
  - `upload/catalog/controller/extension/payment/bmaglasspay.php`
  - `upload/catalog/language/en-gb/extension/payment/bmaglasspay.php`
  - `upload/catalog/model/extension/payment/bmaglasspay.php`
  - `upload/catalog/view/theme/default/template/extension/payment/bmaglasspay.twig`
  - `upload/system/library/bmaglass/ApiException.php`
  - `upload/system/library/bmaglass/Client.php`

## bmaglasspay-opencart-4-1.0.0.zip

- Size: 14304 bytes
- SHA-256: `f94cbdadc684157896266b60abbab507241fbab4e9ced5aa893cce71100d66ad`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-opencart-4-1.0.0.zip
- Install: OpenCart admin → Extensions → Installer
- Files (10):
  - `INSTALL/README.md`
  - `upload/extension/bmaglasspay/admin/controller/payment/bmaglasspay.php`
  - `upload/extension/bmaglasspay/admin/language/en-gb/payment/bmaglasspay.php`
  - `upload/extension/bmaglasspay/admin/view/template/payment/bmaglasspay.twig`
  - `upload/extension/bmaglasspay/catalog/controller/payment/bmaglasspay.php`
  - `upload/extension/bmaglasspay/catalog/language/en-gb/payment/bmaglasspay.php`
  - `upload/extension/bmaglasspay/catalog/model/payment/bmaglasspay.php`
  - `upload/extension/bmaglasspay/catalog/view/template/payment/bmaglasspay.twig`
  - `upload/system/library/bmaglass/ApiException.php`
  - `upload/system/library/bmaglass/Client.php`

## bmaglasspay-prestashop-1.7-1.0.0.zip

- Size: 13161 bytes
- SHA-256: `f9c7c63400025c1db1cba2f9ae0b0b1379826e39f78683606b73ba4afd0dc264`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-prestashop-1.7-1.0.0.zip
- Install: PrestaShop back office → Module Manager → Upload a module
- Files (8):
  - `INSTALL/README.md`
  - `bmaglasspay/bmaglasspay.php`
  - `bmaglasspay/classes/sdk/ApiException.php`
  - `bmaglasspay/classes/sdk/Client.php`
  - `bmaglasspay/controllers/front/redirect.php`
  - `bmaglasspay/controllers/front/webhook.php`
  - `bmaglasspay/views/templates/front/payment_infos.tpl`
  - `bmaglasspay/views/templates/front/payment_return.tpl`

## bmaglasspay-prestashop-8-1.0.0.zip

- Size: 13131 bytes
- SHA-256: `bc08fd47acdaee6d68c4c87404b35b066bd299946c7e46061cc1f63c988a7205`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-prestashop-8-1.0.0.zip
- Install: PrestaShop back office → Module Manager → Upload a module
- Files (8):
  - `INSTALL/README.md`
  - `bmaglasspay/bmaglasspay.php`
  - `bmaglasspay/classes/sdk/ApiException.php`
  - `bmaglasspay/classes/sdk/Client.php`
  - `bmaglasspay/controllers/front/redirect.php`
  - `bmaglasspay/controllers/front/webhook.php`
  - `bmaglasspay/views/templates/front/payment_infos.tpl`
  - `bmaglasspay/views/templates/front/payment_return.tpl`

## bmaglasspay-prestashop-9-1.0.0.zip

- Size: 13486 bytes
- SHA-256: `bffffc681b26fe340cf5fbd1d12a446fabc3416cce1552373a0eef9da5a79e0d`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-prestashop-9-1.0.0.zip
- Install: PrestaShop back office → Module Manager → Upload a module
- Files (9):
  - `INSTALL/README.md`
  - `bmaglasspay/bmaglasspay.php`
  - `bmaglasspay/classes/sdk/ApiException.php`
  - `bmaglasspay/classes/sdk/Client.php`
  - `bmaglasspay/composer.json`
  - `bmaglasspay/controllers/front/redirect.php`
  - `bmaglasspay/controllers/front/webhook.php`
  - `bmaglasspay/views/templates/front/payment_infos.tpl`
  - `bmaglasspay/views/templates/front/payment_return.tpl`

## bmaglasspay-sdk-flutter-1.0.0.zip

- Size: 11151 bytes
- SHA-256: `684aceafd6022edd24d4cc4f0aaedcecfefde61641e2c2634bb0de25d16937db`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-sdk-flutter-1.0.0.zip
- Install: Drop into your Flutter / Dart project (`bmaglass_sdk`)
- Files (9):
  - `LICENSE`
  - `README.md`
  - `lib/bmaglass_sdk.dart`
  - `lib/src/client.dart`
  - `lib/src/errors.dart`
  - `lib/src/models.dart`
  - `lib/src/webhook.dart`
  - `pubspec.yaml`
  - `test/bmaglass_sdk_test.dart`

## bmaglasspay-sdk-php-1.0.0.zip

- Size: 11133 bytes
- SHA-256: `af709c37c4e174ba593d4f006d569b8e80670c9643483608b0ab9af0d540c714`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-sdk-php-1.0.0.zip
- Install: Drop `src/` into your project, or require via Composer (`bmaglass/bmaglass-sdk`)
- Files (6):
  - `LICENSE`
  - `README.md`
  - `composer.json`
  - `src/ApiException.php`
  - `src/Client.php`
  - `tests/run-tests.php`

## bmaglasspay-sdk-python-1.0.0.zip

- Size: 4556 bytes
- SHA-256: `70a3b616d168a9e0b50b0c7588835c340421fdd5dad904ef6eb8580c2fb0635e`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-sdk-python-1.0.0.zip
- Install: Drop `bmaglass_sdk/` into your project (`bmaglass-sdk`)
- Files (4):
  - `LICENSE`
  - `README.md`
  - `bmaglass_sdk/__init__.py`
  - `pyproject.toml`

## bmaglasspay-sdk-react-native-1.0.0.zip

- Size: 11389 bytes
- SHA-256: `db10e40dde97f894f7a2f1704d4325f738cc1d97bb4b8f7bb8de0a582517f585`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-sdk-react-native-1.0.0.zip
- Install: Drop into your React Native / Expo project (`@bmaglass/sdk-react-native`)
- Files (7):
  - `LICENSE`
  - `README.md`
  - `package.json`
  - `src/index.ts`
  - `src/sha256.ts`
  - `test/run-tests.mjs`
  - `tsconfig.json`

## bmaglasspay-sdk-typescript-1.0.0.zip

- Size: 12954 bytes
- SHA-256: `da584225fc896f1d48786da32918f415d5eaa3ea7b1d0014cd86a52b38df3363`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-sdk-typescript-1.0.0.zip
- Install: Drop in / `npm install` from the package in the zip (`@bmaglass/sdk`)
- Files (8):
  - `LICENSE`
  - `README.md`
  - `dist/index.d.ts`
  - `dist/index.js`
  - `package-lock.json`
  - `package.json`
  - `src/index.ts`
  - `tsconfig.json`

## bmaglasspay-woocommerce-1.0.0.zip

- Size: 30014 bytes
- SHA-256: `f9ddf0783258b5e76e1ed79b2cc35cfbe18b89bfa21e759c76c247786d7fc6b2`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-woocommerce-1.0.0.zip
- Install: WordPress Admin → Plugins → Add New → Upload
- Files (12):
  - `bmaglass-pay/INSTALL.md`
  - `bmaglass-pay/assets/js/blocks-integration.js`
  - `bmaglass-pay/assets/js/momo-blocks-integration.js`
  - `bmaglass-pay/bmaglass-pay.php`
  - `bmaglass-pay/includes/class-bmaglass-api-client.php`
  - `bmaglass-pay/includes/class-bmaglass-blocks-integration.php`
  - `bmaglass-pay/includes/class-bmaglass-momo-blocks-integration.php`
  - `bmaglass-pay/includes/class-bmaglass-reconciler.php`
  - `bmaglass-pay/includes/class-wc-gateway-bmaglass-momo.php`
  - `bmaglass-pay/includes/class-wc-gateway-bmaglass.php`
  - `bmaglass-pay/readme.txt`
  - `bmaglass-pay/uninstall.php`
