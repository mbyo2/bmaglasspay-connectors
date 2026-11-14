# BMaGlass Pay SDK source zips — validation evidence

Branch: `bmg-zips-sdk` (from `origin/main` @ `ee69b04`)
Built: 2026-11-14. All zips are ready-to-drop-in packages: source + README + LICENSE (MIT) + package manifest + built output where the SDK ships it. No node_modules, no `__pycache__`, no `.dart_tool`, no secrets, no mock data. Each README covers install (drop in / install per manifest → paste API key from the BMaGlass dashboard → done) plus the one-line usage snippet; no README edits were needed. No functional code was modified.

## Per-zip record

### 1. bmaglasspay-sdk-typescript-1.0.0.zip
- Path: `dist/bmaglasspay-sdk-typescript-1.0.0.zip`
- Size: 13,644 bytes
- SHA-256: `8d69ad3cc4478505d4bbdd9252f50a8537e81042f74078f5384855124486326d`
- Contents: `src/index.ts`, `dist/index.js`, `dist/index.d.ts` (rebuilt fresh with `npm run build`, tsc 5.5), `package.json`, `package-lock.json`, `tsconfig.json`, `README.md`, `LICENSE`
- Validation (against unzipped code, fresh `npm install` + `npm run build` in the temp dir, then the repo contract test `sdk/tests/ts-sdk.contract.mjs`): **39 assertions, ALL PASS, 0 fail**

### 2. bmaglasspay-sdk-python-1.0.0.zip
- Path: `dist/bmaglasspay-sdk-python-1.0.0.zip`
- Size: 4,918 bytes
- SHA-256: `1ddb592803da775df5f46124770823aefae1595c0eedd689957630651cc8db04`
- Contents: `bmaglass_sdk/__init__.py`, `pyproject.toml`, `README.md`, `LICENSE`
- Validation (against unzipped code, `python3 -m unittest discover sdk/tests -v` with the repo test `sdk/tests/test_python_sdk.py`): **8 tests, OK, 0 fail**

### 3. bmaglasspay-sdk-php-1.0.0.zip
- Path: `dist/bmaglasspay-sdk-php-1.0.0.zip`
- Size: 11,721 bytes
- SHA-256: `dae22642c4a3400c6a2f74ad8c4669a78d891ae46120df74bac298eb7c20394f`
- Contents: `src/Client.php`, `src/ApiException.php`, `tests/run-tests.php`, `composer.json`, `README.md`, `LICENSE`
- Validation (against unzipped code, PHP 8.3 CLI installed for this run, `php tests/run-tests.php`): **26 PASS, 0 FAIL — "ALL PHP SDK TESTS PASSED"**

### 4. bmaglasspay-sdk-flutter-1.0.0.zip
- Path: `dist/bmaglasspay-sdk-flutter-1.0.0.zip`
- Size: 12,037 bytes
- SHA-256: `3c7d7e5069c16518f1a576561233b52bf85f1a3e74571c150b51a81fe5fc5e6f`
- Contents: `lib/bmaglass_sdk.dart`, `lib/src/client.dart`, `lib/src/errors.dart`, `lib/src/models.dart`, `lib/src/webhook.dart`, `test/bmaglass_sdk_test.dart`, `pubspec.yaml`, `README.md`, `LICENSE`
- Validation (against unzipped code, Dart SDK 3.14.0 downloaded for this run, `dart pub get && dart test`): **10/10 tests passed — "All tests passed!"** (10 `test()` cases enumerated in `test/bmaglass_sdk_test.dart`, all executed)

### 5. bmaglasspay-sdk-react-native-1.0.0.zip
- Path: `dist/bmaglasspay-sdk-react-native-1.0.0.zip`
- Size: 12,027 bytes
- SHA-256: `80b5a52f8066636600df1db170660389d77f9bd137367abd45cc6a6fab989e1b`
- Contents: `src/index.ts`, `src/sha256.ts`, `test/run-tests.mjs`, `package.json`, `tsconfig.json`, `README.md`, `LICENSE`
- Validation (against unzipped code, `node test/run-tests.mjs` on Node 24): **22 PASS, 0 FAIL — "ALL REACT NATIVE SDK TESTS PASSED"**

### 6. bmaglasspay-embed-button-1.0.0.zip
- Path: `dist/bmaglasspay-embed-button-1.0.0.zip`
- Size: 5,006 bytes
- SHA-256: `32566f3d0a957357730b7fb4ccea3055464c47b7d7885f178452fca438c26125`
- Contents: `bmaglass-pay-button.js`, `demo.html`, `README.md`, `LICENSE`
- Validation (against unzipped code, repo test `integrations/embed/test-embed.mjs` run beside the unzipped files): **11 PASS, 0 FAIL — "ALL EMBED TESTS PASSED"**
- Secret check: `demo.html` contains only the public payment-link URL placeholder — no API key anywhere in the zip (the script itself refuses to render if a `bmg_`/`bmp_` key is placed in its config).

## Notes
- `sdk/typescript/dist/` was rebuilt fresh from current source for the TS zip; the rebuilt `dist/` is gitignored in the repo (no repo change).
- PHP 8.3 CLI and Dart SDK 3.14.0 were installed/downloaded on this VM only for validation; they are not part of the zips. The Dart scratch dir (`~/workspace/.dart-tmp`) was removed after validation.
- `dist/` is gitignored in the repo (root `.gitignore`: `dist/`); the zips + this file were force-added for this delivery.
