import 'app.dart';
import 'constants/flavor_config.dart';

/// Vendor flavour entry point.
///
///   flutter run --flavor vendor -t lib/main_vendor.dart
Future<void> main() => bootstrap(FlavorConfig.vendor);
