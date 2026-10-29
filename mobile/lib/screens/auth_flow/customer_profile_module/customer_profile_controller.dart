import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart' show Constants;
import '../customer_home_module/customer_home_controller.dart';
import '../../../utils/place_service.dart';
import '../customer_login_module/customer_login_view.dart';
import '../personal_details_module/personal_details_view.dart';
import '../customer_select_location_module/customer_select_location_view.dart';
import '../customer_saved_addresses_module/customer_saved_addresses_view.dart';
import '../customer_set_location_module/customer_set_location_view.dart';

/// Customer profile (SPEC section 4) — the account actions that used to
/// be crammed into the home screen's app bar, plus the saved location
/// and preferences.
class CustomerProfileController extends GetxController {
  /// Local only. There is no notification-preference endpoint yet, so
  /// this writes through [Injector] to the device and nothing else —
  /// see [toggleNotifications].
  bool notificationsEnabled = Injector.enableNotification;

  String get displayName {
    final name = Injector.userData?.name?.trim() ?? '';

    return name.isEmpty ? tr(StringRes.yourNameFallback) : name;
  }

  String get email => Injector.userData?.email ?? '';

  /// Two letters for the avatar, when there is no photo to show.
  String get initials {
    final parts = displayName
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

  String? get avatarUrl => Injector.userData?.avatarUrl;

  Future<void> changePhoto() async {
    if (await changeProfilePhoto(hasPhoto: (avatarUrl ?? '').isNotEmpty) != null) {
      update();
    }
  }

  /// The edit screen writes the saved user into [Injector]; redrawing on
  /// the way back picks up the new name and photo.
  Future<void> openPersonalDetails() async {
    await Get.to(() => const PersonalDetailsView());
    update();
  }

  String get locationLabel =>
      Injector.customerLocationLabel ?? tr(StringRes.noLocationSet);

  /// Opens the location picker and keeps whatever comes back.
  ///
  /// Writes straight through to [Injector] rather than returning it:
  /// the home screen reads the same stored value, so both screens stay
  /// in step without one having to tell the other.
  Future<void> changeLocation() async {
    final result = await Get.to<PlaceSuggestion?>(
      // Constants.useNewLocationFlow: false restores the previous picker.
      () => Constants.useNewLocationFlow
          ? const CustomerSetLocationView()
          : const CustomerSelectLocationView(),
    );

    if (result == null || !result.hasPoint) {
      return;
    }

    await Injector.setCustomerLocation(
      label: result.title,
      address: result.fullAddress,
      latitude: result.latitude!,
      longitude: result.longitude!,
    );

    // Tells the server too, so vendor matching and the customer record
    // agree with what the app is showing.
    await DataSource.instance.updateCustomerLocationAPI(
      latitude: result.latitude,
      longitude: result.longitude,
    );

    update();
  }

  /// Profile → My addresses (new location flow). Choosing an address there
  /// writes the current location to [Injector] itself, so this only has to
  /// redraw the row's subtitle on return.
  Future<void> openMyAddresses() async {
    await Get.to(() => const CustomerSavedAddressesView());
    update();
  }

  void toggleNotifications(bool value) {
    notificationsEnabled = value;
    Injector.enableNotification = value;
    Injector.prefs?.setBool(PrefKeys.enableNotification, value);
    update();
  }

  /// Applies immediately on this device; the server copy is best-effort,
  /// so a failed save never undoes the language the user can see.
  Future<void> changeLanguageAPI(String code) async {
    await Utils.applyLanguage(code);
    update();

    // Category and vendor names come from the server in the request's
    // language, so the home tab already on screen has to fetch them again.
    if (Get.isRegistered<CustomerHomeController>()) {
      final home = Get.find<CustomerHomeController>();
      home.fetchCategoriesAPI();
      home.fetchNearbyVendorsAPI();
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
        print('Customer logout error $e');
      }
    }

    await Injector.clearUserData();

    Utils.showToast(tr(StringRes.logout));
    Utils.transitionWithOffAll(const CustomerLoginView());
  }
}
