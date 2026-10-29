import 'app.dart';
import 'constants/flavor_config.dart';

/// Salesman flavour entry point.
///
///   flutter run --flavor salesman -t lib/main_salesman.dart
Future<void> main() => bootstrap(FlavorConfig.salesman);
