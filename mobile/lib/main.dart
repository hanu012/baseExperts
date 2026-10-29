import 'app.dart';
import 'constants/flavor_config.dart';

/// Default entry point, kept so a bare `flutter run` still works. It boots the
/// customer flavour.
///
/// Prefer the explicit form, which also selects the matching Android flavour
/// so the three apps install side by side:
///
///   flutter run --flavor salesman -t lib/main_salesman.dart
///   flutter run --flavor vendor   -t lib/main_vendor.dart
///   flutter run --flavor customer -t lib/main_customer.dart
Future<void> main() => bootstrap(FlavorConfig.customer);
