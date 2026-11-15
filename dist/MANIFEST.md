# BMaGlass Pay downloadable zips — manifest

Release version: **1.0.0** (see `VERSION` at the repo root). Built by `scripts/build-zips.sh` from a clean checkout; rebuilding from the same tree is byte-identical (fixed timestamps, see the build scripts).

Component-internal versions stay as-is: the WooCommerce plugin header is 1.2.0, the SDKs are 1.0.0. The zip release version is 1.0.0 for every file.

Every zip carries install instructions: `INSTALL/README.md` at its root (store modules, automation) or the package README (SDKs, embed); the WooCommerce zip is the plugin folder verbatim, including its `INSTALL.md`.

## bmaglasspay-automation-1.0.0.zip

- Size: 8455 bytes
- SHA-256: `c65ba8276256acb7302dc0d2af97abc98bb12e8640cee7bb7b19c0adf9c587a1`
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

- Size: 17721 bytes
- SHA-256: `757dba6397448f4e7cf6a305e5383e3eb92b71562526fefe9d99af119cb46a50`
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

- Size: 20575 bytes
- SHA-256: `ed524e9d8b461f3f2f2e4aa64d48a32949a4e2af4f589512f7624d00690d1d59`
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

- Size: 21092 bytes
- SHA-256: `eff4e1b3349b09068479af71421c2baec3df8a91fa92b0392abd82b7e5797f47`
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

- Size: 21061 bytes
- SHA-256: `6e761d599f279d0a2849cb7ce9a643dcb99f61ecb30940339ca44cdc4ceae4e5`
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

- Size: 21235 bytes
- SHA-256: `1ead1e4f8c819fd02bed191a702e4798800aae48a69815db3e7e003c435faa2c`
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

- Size: 23298 bytes
- SHA-256: `62d6a85ee3372e7c01b708c5704c551ad104f4627a2beb85eaa595e1c8579068`
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

- Size: 15442 bytes
- SHA-256: `03f0f73b48cab04de040beafdae815db422602952fcaa978f6114ff621f73aa5`
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

- Size: 14675 bytes
- SHA-256: `85805582f51116a8439807eb89f8e627f8f295f5b484cc347101b2aea0f5945b`
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

- Size: 13591 bytes
- SHA-256: `942a6cae2d717756d63c6aac53c1bc372c0b01bf6f1265ab40cdeb0580c9b004`
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

- Size: 13561 bytes
- SHA-256: `a729586b36a12e8cf42f2e1137c1cbe28740a69a710befd049b63a1a218f87ba`
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

- Size: 13916 bytes
- SHA-256: `c2158d365891dec8d89af353995f087375c3b51c2dc756cde67f1339971c9603`
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

- Size: 13889 bytes
- SHA-256: `709b6a59a7bfc923b83b73984a073267082e9e9946120f29fe92a1befde23e48`
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

- Size: 13039 bytes
- SHA-256: `1bc04cc36e5f105e59a6cd7e956b8bfd37dbf2046398d805549097655cda30bc`
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

- Size: 5718 bytes
- SHA-256: `0aa1910372254dd89cd2a419719aa97d72860ce4a1ad07ea37b70806e3dae225`
- Download: https://hhxwwozpvgktuqutfkhc.supabase.co/storage/v1/object/public/downloads/bmaglasspay-sdk-python-1.0.0.zip
- Install: Drop `bmaglass_sdk/` into your project (`bmaglass-sdk`)
- Files (4):
  - `LICENSE`
  - `README.md`
  - `bmaglass_sdk/__init__.py`
  - `pyproject.toml`

## bmaglasspay-sdk-react-native-1.0.0.zip

- Size: 14171 bytes
- SHA-256: `d62d940f88fb84eab60b8d2f63b97bc8af9fb7cc0aa374d3e54886f0e15b7b80`
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

- Size: 17485 bytes
- SHA-256: `11abb89cec6e12c53a71678a2c59db4b720c402e8f4be20ab5017539ba6b2b3e`
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

- Size: 31767 bytes
- SHA-256: `4c6bef1c4c981c9184bf5dc44477cc88341f6457b43e90d873325bf9451d27ad`
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
