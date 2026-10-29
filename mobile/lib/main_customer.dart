import 'app.dart';
import 'constants/flavor_config.dart';

/// Customer flavour entry point.
///
///   flutter run --flavor customer -t lib/main_customer.dart
Future<void> main() => bootstrap(FlavorConfig.customer);
