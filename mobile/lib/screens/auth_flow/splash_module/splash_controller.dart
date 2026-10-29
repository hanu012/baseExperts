import '../../../constants/app.export.dart';
import '../../../constants/flavor_config.dart';
import '../account_verification_module/account_verification_view.dart';
import '../change_password_module/change_password_view.dart';
import '../customer_home_module/customer_home_view.dart';
import '../customer_login_module/customer_login_view.dart';
import '../salesman_home_module/salesman_home_view.dart';
import '../salesman_login_module/salesman_login_view.dart';
import '../vendor_landing_module/vendor_landing_view.dart';
import '../vendor_login_module/vendor_login_view.dart';

/// First screen of all three flavours: shows the brand briefly, then
/// replaces itself with the right entry screen.
///
/// The entry-screen decision used to live in app.dart's `home:`; it moved
/// here unchanged. offAll is correct for leaving: nothing sits beneath the
/// splash, so there is no live screen for it to duplicate.
class SplashController extends GetxController {
  /// Long enough to read the brand, short enough not to feel like a wait.
  static const Duration displayFor = Duration(milliseconds: 1500);

  FlavorConfig get config => FlavorConfig.current;

  IconData get brandIcon {
    switch (config.flavor) {
      case Flavor.salesman:
        return Icons.explore_outlined;
      case Flavor.vendor:
        return Icons.storefront_outlined;
      case Flavor.customer:
        return Icons.handyman_outlined;
    }
  }

  String get brandTitle {
    switch (config.flavor) {
      case Flavor.salesman:
        return StringRes.salesmanPortal;
      case Flavor.vendor:
        return StringRes.vendorPortal;
      case Flavor.customer:
        return StringRes.customerPortal;
    }
  }

  String get brandSubtitle {
    switch (config.flavor) {
      case Flavor.salesman:
        return StringRes.salesmanPortalDesc;
      case Flavor.vendor:
        return StringRes.vendorPortalDesc;
      case Flavor.customer:
        return StringRes.customerPortalDesc;
    }
  }

  @override
  void onReady() {
    super.onReady();
    _continueAfterSplash();
  }

  Future<void> _continueAfterSplash() async {
    await Future.delayed(displayFor);

    // The controller may already be gone (e.g. app backgrounded and torn
    // down) — navigating from a closed controller would push onto nothing.
    if (isClosed) {
      return;
    }

    Utils.transitionWithOffAll(entryScreen());
  }

  /// Each flavour's entry screen. Both gates are re-checked here, not just
  /// at login: the stored session outlives the app, so a user who was
  /// approved yesterday and rejected overnight must not be dropped onto a
  /// home screen the server will refuse to serve.
  Widget entryScreen() {
    switch (config.flavor) {
      case Flavor.salesman:
        // Already signed in: skip login, but honour a pending forced change
        // (SPEC section 2.1) — the server blocks every other endpoint until
        // it is done.
        if (Injector.isLoggedIn) {
          return _gatedOr(const SalesmanHomeView());
        }

        return const SalesmanLoginView();
      case Flavor.vendor:
        // Same forced-change rule — SPEC section 2.1 is platform-wide, so an
        // admin-created vendor account still needs it.
        if (Injector.isLoggedIn) {
          return _gatedOr(const VendorLandingView());
        }

        return const VendorLoginView();
      case Flavor.customer:
        // No forced-change branch of their own, but subject to the approval
        // gate like every other role.
        if (Injector.isLoggedIn) {
          return _gatedOr(const CustomerHomeView());
        }

        return const CustomerLoginView();
    }
  }

  /// [home] unless the stored session is blocked by one of the two
  /// platform-wide gates, in the same order the server applies them:
  /// RequirePasswordChange before RequireApprovedAccount.
  Widget _gatedOr(Widget home) {
    final user = Injector.userData;

    if (user?.mustChangePassword ?? false) {
      return const ChangePasswordView();
    }

    if (!(user?.isApproved ?? false)) {
      return const AccountVerificationView();
    }

    return home;
  }
}
