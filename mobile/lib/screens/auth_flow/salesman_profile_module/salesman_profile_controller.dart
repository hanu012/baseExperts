import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../personal_details_module/personal_details_view.dart';
import '../salesman_home_module/salesman_home_controller.dart';
import '../salesman_login_module/salesman_login_view.dart';

/// The salesman's own profile (SPEC section 2.5).
///
/// Backed by `GET /api/salesmen/me`, which bundles identity, preferences
/// and headline stats in one call — the screen renders all three at once,
/// so splitting them would mean three round trips to draw it.
class SalesmanProfileController extends GetxController {
  SalesmanProfileModel? profile;
  bool isLoading = false;
  bool hasError = false;

  /// Guards the notification switch while its PATCH is in flight, so a
  /// double tap cannot fire two conflicting writes.
  bool isSavingPreference = false;

  @override
  void onInit() {
    super.onInit();
    fetchProfileAPI();
  }

  Future<void> fetchProfileAPI() async {
    isLoading = true;
    hasError = false;
    update();

    try {
      final response = await DataSource.instance.salesmanMeAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        hasError = true;
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      profile = SalesmanProfileModel.fromJson(response.data as Map<String, dynamic>);

      // Keep the device copy in step with the server's, so the rest of the
      // app (which reads Injector) does not disagree with this screen.
      Injector.enableNotification = profile!.enableNotification;
    } catch (e) {
      if (kDebugMode) {
        print('Fetch salesman profile error $e');
      }
      hasError = true;
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      update();
    }
  }

  Future<void> changePhoto() async {
    final user = await changeProfilePhoto(
      hasPhoto: (profile?.avatarUrl ?? '').isNotEmpty,
    );

    if (user != null && profile != null) {
      profile!.avatarUrl = user.avatarUrl;
      update();
    }
  }

  /// The edit screen saves through PATCH /user/profile, so the profile is
  /// refetched on the way back to show what was stored. [footer] holds the
  /// admin-owned fields, shown under the form as values.
  Future<void> openPersonalDetails({Widget? footer}) async {
    final saved = await Get.to<bool>(
      () => PersonalDetailsView(phoneRequired: true, footer: footer),
    );

    if (saved == true) {
      fetchProfileAPI();
    }
  }

  /// Optimistic: the switch flips immediately and rolls back if the server
  /// refuses, because a toggle that lags a round trip feels broken.
  Future<void> setNotifications(bool enabled) async {
    if (isSavingPreference || profile == null) {
      return;
    }

    final previous = profile!.enableNotification;

    isSavingPreference = true;
    profile!.enableNotification = enabled;
    update();

    try {
      final response = await DataSource.instance.updatePreferencesAPI(
        body: {'enable_notification': enabled},
      );

      if (response == null || !response.isSuccess) {
        profile!.enableNotification = previous;
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      Injector.enableNotification = enabled;
      await Injector.prefs?.setBool(PrefKeys.enableNotification, enabled);
    } catch (e) {
      profile!.enableNotification = previous;
      if (kDebugMode) {
        print('Update preference error $e');
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
    profile?.language = code;
    update();

    // Plan names on the home screen come from the server in the request's
    // language, so the screen beneath this one has to fetch again.
    if (Get.isRegistered<SalesmanHomeController>()) {
      Get.find<SalesmanHomeController>().fetchProfileAPI();
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
        print('Salesman logout error $e');
      }
    }

    await Injector.clearUserData();

    Utils.showToast(tr(StringRes.logout));
    Utils.transitionWithOffAll(const SalesmanLoginView());
  }
}
