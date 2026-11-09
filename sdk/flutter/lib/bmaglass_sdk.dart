/// BMaGlass Pay SDK for Flutter / Dart.
///
/// A thin client over the BMaGlass Pay developer API — the same
/// surface as the TypeScript, Python, PHP and React Native SDKs.
///
/// Auth: an API key created in the BMaGlass Pay app. Keys carry
/// their environment: `bmg_live_` (legacy `bmp_live_`) keys move real
/// money; `bmg_sandbox_` (legacy `bmp_test_`) keys run on mainnet
/// formats with simulated money — sandbox payments settle only via
/// the simulate-payment endpoints.
/// Real-money writes with a sandbox key throw a [BMaGlassException] with code
/// `test_mode_read_only`.
library bmaglass_sdk;

export 'src/client.dart';
export 'src/errors.dart';
export 'src/models.dart';
export 'src/webhook.dart';
