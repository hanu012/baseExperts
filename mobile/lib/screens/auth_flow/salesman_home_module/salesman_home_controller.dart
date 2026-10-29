import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../salesman_login_module/salesman_login_view.dart';

/// Salesman home (SPEC sections 2.3-2.5).
///
/// Owns the hero's headline numbers — total vendors, how many are
/// subscribed, and earnings — which come from `GET /api/salesmen/me`
/// rather than being counted off the vendor list. The list is the
/// salesman's own vendors only, while "subscribed" has to respect the same
/// active-or-in-grace window the server uses; recomputing that here is how
/// the header and the rows start disagreeing.
class SalesmanHomeController extends GetxController {
  SalesmanProfileModel? profile;
  bool isLoadingProfile = false;

  @override
  void onInit() {
    super.onInit();
    fetchProfileAPI();
  }

  /// Best-effort: the tabs below carry the screen, so a failed stats fetch
  /// dims the hero rather than blocking everything behind an error state.
  Future<void> fetchProfileAPI() async {
    isLoadingProfile = true;
    update();

    try {
      final response = await DataSource.instance.salesmanMeAPI();

      if (response != null && response.isSuccess && response.data != null) {
        profile = SalesmanProfileModel.fromJson(response.data as Map<String, dynamic>);
      }
    } catch (e) {
      if (kDebugMode) {
        print('Fetch salesman home stats error $e');
      }
    } finally {
      isLoadingProfile = false;
      update();
    }
  }

  String get greetingName => profile?.name ?? Injector.userData?.name ?? '';

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
