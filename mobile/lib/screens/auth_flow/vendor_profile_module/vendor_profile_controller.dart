import 'dart:async';

import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../vendor_dashboard_module/vendor_dashboard_controller.dart';
import '../vendor_login_module/vendor_login_view.dart';

/// The vendor's own profile screen (SPEC section 3.2 / section 2.5's
/// preference pair).
///
/// Fetches its own copy of GET /api/vendors/me rather than reading the
/// dashboard's: this screen is pushed over the shell and outlives
/// individual tab switches, and a profile that silently shows stale
/// business details because the tab behind it has not refreshed is worse
/// than one extra request on open.
class VendorProfileController extends GetxController {
  VendorMeModel? vendorMe;
  bool isLoading = false;
  bool isSavingPreference = false;

  /// Only used to name the next tier up in the Subscription group.
  List<PlanModel> plans = [];

  /// Mirrors the stored preference so the switch is live immediately
  /// rather than waiting on the first fetch.
  bool enableNotification = Injector.enableNotification;

  @override
  void onInit() {
    super.onInit();
    fetchVendorMeAPI();
  }

  Future<void> fetchVendorMeAPI() async {
    isLoading = true;
    update();

    try {
      final response = await DataSource.instance.vendorMeAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      vendorMe = VendorMeModel.fromJson(response.data as Map<String, dynamic>);

      // Fire-and-forget: only the upgrade row needs these, so a failure
      // hides that row rather than failing the whole profile.
      unawaited(fetchPlansAPI());
    } catch (e) {
      if (kDebugMode) {
        print('Fetch vendor profile error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      update();
    }
  }

  /// Plans, purely so the Subscription group can name the next tier up.
  Future<void> fetchPlansAPI() async {
    try {
      final response = await DataSource.instance.plansAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        return;
      }

      plans = (response.data as List<dynamic>)
          .map((e) => PlanModel.fromJson(e as Map<String, dynamic>))
          .toList();

      update();
    } catch (e) {
      if (kDebugMode) {
        print('Fetch plans for profile error $e');
      }
    }
  }

  /// The cheapest plan dearer than the current one, or null on the top
  /// tier — in which case the profile hides the upgrade row rather than
  /// offering an upgrade that does not exist.
  PlanModel? get upgradePlan {
    final currentName = vendorMe?.activeSubscription?.planName;

    if (currentName == null || plans.isEmpty) {
      return null;
    }

    final current = plans.where((p) => p.name == currentName).firstOrNull;

    if (current == null) {
      return null;
    }

    final dearer = plans
        .where((p) => (p.pricePaise ?? 0) > (current.pricePaise ?? 0))
        .toList()
      ..sort((a, b) => (a.pricePaise ?? 0).compareTo(b.pricePaise ?? 0));

    return dearer.firstOrNull;
  }

  /// Two letters for the avatar tile.
  String get initials {
    final parts = (vendorMe?.businessName ?? '')
        .trim()
        .split(RegExp(r'\s+'))
        .where((part) => part.isNotEmpty)
        .toList();

    if (parts.isEmpty) {
      return '?';
    }

    if (parts.length == 1) {
      final one = parts.first;

      return (one.length >= 2 ? one.substring(0, 2) : one).toUpperCase();
    }

    return '${parts.first[0]}${parts[1][0]}'.toUpperCase();
  }

  /// Optimistic, with a rollback: the switch is the kind of control people
  /// expect to move the instant they touch it, and a request that fails
  /// has to put it back rather than leave the UI lying.
  Future<void> setNotifications(bool enabled) async {
    if (isSavingPreference) {
      return;
    }

    final previous = enableNotification;

    isSavingPreference = true;
    enableNotification = enabled;
    update();

    try {
      final response = await DataSource.instance.updatePreferencesAPI(
        body: {'enable_notification': enabled},
      );

      if (response == null || !response.isSuccess) {
        enableNotification = previous;
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      Injector.enableNotification = enabled;
      await Injector.prefs?.setBool(PrefKeys.enableNotification, enabled);
    } catch (e) {
      enableNotification = previous;
      if (kDebugMode) {
        print('Vendor preference error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isSavingPreference = false;
      update();
    }
  }

  /// Applies immediately on this device; the server copy is best-effort,
  /// so a failed save never undoes the language the user can see.
  Future<void> changeLanguageAPI(String code) async {
    await Utils.applyLanguage(code);
    update();

    // Plan and service names come from the server in the request's
    // language, so the dashboard already on screen has to fetch again.
    if (Get.isRegistered<VendorDashboardController>()) {
      Get.find<VendorDashboardController>().fetchVendorMeAPI();
    }

    try {
      await DataSource.instance.updatePreferencesAPI(body: {'language': code});
    } catch (e) {
      if (kDebugMode) {
        print('Save language error $e');
      }
    }
  }

  Future<void> logoutAPI() async {
    try {
      Utils.showCircularProgressLottie(true);
      await DataSource.instance.logoutAPI();
      Utils.showCircularProgressLottie(false);
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Vendor logout error $e');
      }
    }

    await Injector.clearUserData();

    Utils.showToast(tr(StringRes.logout));
    Utils.transitionWithOffAll(const VendorLoginView());
  }
}
