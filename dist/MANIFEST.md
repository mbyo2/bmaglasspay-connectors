# BMaGlass Pay downloadable zips — manifest

Release version: **1.0.0** (see `VERSION` at the repo root). Built by `scripts/build-zips.sh` from a clean checkout; rebuilding from the same tree is byte-identical (fixed timestamps, see the build scripts).

Component-internal versions stay as-is: the WooCommerce plugin header is 1.2.0, the SDKs are 1.0.0. The zip release version is 1.0.0 for every file.

Every zip carries install instructions: `INSTALL/README.md` at its root (store modules, automation) or the package README (SDKs, embed); the WooCommerce zip is the plugin folder verbatim, including its `INSTALL.md`.

## bmaglasspay-automation-1.0.0.zip

- Size: 10147 bytes
- SHA-256: `e0b958735eecdc967f098821eaa8add631adc8f8d549d5825ed86d9e89cc16cb`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-automation-1.0.0.zip
- Install: Unzip, read `README.md`, import the Zapier / Make recipe
- Files (11):
  - `INSTALL/README.md`
  - `LICENSE`
  - `README.md`
  - `examples/invoice-created.json`
  - `examples/invoice-paid.json`
  - `examples/mobile-money-collection-completed.json`
  - `examples/payment-link-created.json`
  - `examples/payment-link-paid.json`
  - `examples/transaction-completed.json`
  - `examples/webhook-test.json`
  - `zapier/triggers-and-creates.json`

## bmaglasspay-embed-button-1.0.0.zip

- Size: 5333 bytes
- SHA-256: `a39b8e4a59773146b46bd47184c5577528f44656687a751a8605ab8943684dd7`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-embed-button-1.0.0.zip
- Install: Copy `bmaglass-pay-button.js` into any web page (see `demo.html`)
- Files (4):
  - `LICENSE`
  - `README.md`
  - `bmaglass-pay-button.js`
  - `demo.html`

## bmaglasspay-magento-1.0.0.zip

- Size: 20023 bytes
- SHA-256: `8cb835f3189678062574238903caa80339761554e120b1a65811505fc792cda7`
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

- Size: 24092 bytes
- SHA-256: `7d8dbd12ba5cae987a5d5e6cb5a04b475436da049d6e7deb6c189e642af03f3e`
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

- Size: 28768 bytes
- SHA-256: `4c429e742f377636ed2ee36ea1fd3780e6b8d71a05e34a81e04b51d97d6094cd`
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

- Size: 28771 bytes
- SHA-256: `a8100d80821bd4b487ed5e3880956e7bcf3ea4b0f1c7e2cb5e180e95c27ee1ae`
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

- Size: 28930 bytes
- SHA-256: `ed62e1e7dcb110afc35a40f902914438acd606283989ea77c8ea2b18982bff3d`
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

- Size: 31634 bytes
- SHA-256: `fb87f79f3f579359668c32f5c86e75fea8737b5396a40263b4b0bee04cb31c59`
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

- Size: 17811 bytes
- SHA-256: `2fd2f5c155ced2d5e252d4d698efb7a298cd982ce655966a3c69c8f8c6c7cc96`
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

- Size: 18254 bytes
- SHA-256: `2c3ab1aeb067ea389b8049b1ed502047573af5b951bbad06b080019e824dbde4`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-opencart-4-1.0.0.zip
- Install: OpenCart admin → Extensions → Installer
- Files (11):
  - `INSTALL/README.md`
  - `install.json`
  - `upload/extension/bmaglasspay/admin/controller/payment/bmaglasspay.php`
  - `upload/extension/bmaglasspay/admin/language/en-gb/payment/bmaglasspay.php`
  - `upload/extension/bmaglasspay/admin/view/template/payment/bmaglasspay.twig`
  - `upload/extension/bmaglasspay/catalog/controller/payment/bmaglasspay.php`
  - `upload/extension/bmaglasspay/catalog/language/en-gb/payment/bmaglasspay.php`
  - `upload/extension/bmaglasspay/catalog/model/payment/bmaglasspay.php`
  - `upload/extension/bmaglasspay/catalog/view/template/payment/bmaglasspay.twig`
  - `upload/extension/bmaglasspay/system/library/bmaglass/ApiException.php`
  - `upload/extension/bmaglasspay/system/library/bmaglass/Client.php`

## bmaglasspay-prestashop-1.7-1.0.0.zip

- Size: 15755 bytes
- SHA-256: `638978e363c796694400e5644c6d3f42c8b843cae82048b96e66389693783d60`
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

- Size: 15725 bytes
- SHA-256: `5897a29dcb2061437901ef1c09d4c50f748c1c7139508a8e23162680f26b9861`
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

- Size: 16079 bytes
- SHA-256: `d0e3fa9619572f3d6dd4e2788ed6cb1bc66c490414617815ff70310184ab61e1`
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

- Size: 16781 bytes
- SHA-256: `25cede8a016e082295acc79959c05c27130d53207ee3be0e31c312db6d7bc0ed`
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

- Size: 16506 bytes
- SHA-256: `47ffff62bc7cc42385a98c2243f7ed0892c896108937aa6e8a7c0890101b2b31`
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

- Size: 7431 bytes
- SHA-256: `05257f2bfdf05590769dfd53f6b06a59a708369a408e990e84743252442079ef`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-sdk-python-1.0.0.zip
- Install: Drop `bmaglass_sdk/` into your project (`bmaglass-sdk`)
- Files (4):
  - `LICENSE`
  - `README.md`
  - `bmaglass_sdk/__init__.py`
  - `pyproject.toml`

## bmaglasspay-sdk-react-native-1.0.0.zip

- Size: 17159 bytes
- SHA-256: `c6df1f3f8e2a71d6c74e495eabc16f9283e4b7d5ffc8737c0b0fb38a1d5fa62a`
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

- Size: 20980 bytes
- SHA-256: `40a7f03de59a512c7ab3cb9698e5f89eddae854f3b4c66a2ddbe4831cf0875af`
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

- Size: 35544 bytes
- SHA-256: `28c8288c8b9a8c5866a1070a007291a55b40fa3cd08fe65411f4b32ae6b86af8`
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
