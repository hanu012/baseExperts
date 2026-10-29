import '../constants/constant.dart';

/// The three shipped apps. One codebase, three store listings, one API.
enum Flavor { salesman, vendor, customer }

/// Per-flavor settings, resolved once in each `main_*.dart` before runApp.
///
/// Read it anywhere as `FlavorConfig.current`. Nothing outside this file
/// should branch on the flavor by string.
class FlavorConfig {
  const FlavorConfig({
    required this.flavor,
    required this.appName,
    required this.role,
  });

  final Flavor flavor;

  /// Shown in the task switcher and as GetMaterialApp's title.
  final String appName;

  /// The backend role this app signs users in as — matches the UserRole enum
  /// on the server (SPEC section 1).
  final String role;

  static late FlavorConfig current;

  static void initialize(FlavorConfig config) => current = config;

  bool get isSalesman => flavor == Flavor.salesman;

  bool get isVendor => flavor == Flavor.vendor;

  bool get isCustomer => flavor == Flavor.customer;

  static const FlavorConfig salesman = FlavorConfig(
    flavor: Flavor.salesman,
    appName: 'IBE Salesman',
    role: Constants.salesman,
  );

  static const FlavorConfig vendor = FlavorConfig(
    flavor: Flavor.vendor,
    appName: 'IBE Vendor',
    role: Constants.vendor,
  );

  static const FlavorConfig customer = FlavorConfig(
    flavor: Flavor.customer,
    appName: 'IBE Customer',
    role: Constants.customer,
  );
}
