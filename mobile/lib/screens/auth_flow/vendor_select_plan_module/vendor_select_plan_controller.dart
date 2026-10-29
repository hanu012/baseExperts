import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../vendor_login_module/vendor_login_view.dart';
import '../vendor_select_services_module/vendor_select_services_view.dart';

/// Vendor self-service, step 1 — plan selection (SPEC section 3.2).
///
/// Fresh module, not the salesman-flow SelectPlanController: this screen
/// resolves its own vendor_id from GET /api/vendors/me rather than taking
/// one as a constructor argument — the vendor is never told (or trusted
/// to send) anyone's id but their own, matching how /vendors/me and
/// /salesmen/me/* already avoid a client-supplied id entirely.
class VendorSelectPlanController extends GetxController {
  List<PlanModel> plans = [];
  int? selectedPlanId;
  int? vendorId;

  /// The vendor already had a plan (active, grace or expired): the
  /// services step renews instead of subscribing. A brand-new (draft)
  /// vendor subscribes as before.
  bool isRenewal = false;
  bool isLoading = false;

  @override
  void onInit() {
    super.onInit();
    fetchPlansAPI();
  }

  Future<void> fetchPlansAPI() async {
    isLoading = true;
    update();

    try {
      final responses = await Future.wait([
        DataSource.instance.plansAPI(),
        DataSource.instance.vendorMeAPI(),
      ]);

      final plansResponse = responses[0];
      final meResponse = responses[1];

      if (plansResponse == null ||
          !plansResponse.isSuccess ||
          plansResponse.data == null ||
          meResponse == null ||
          !meResponse.isSuccess ||
          meResponse.data == null) {
        Utils.showToast(
          plansResponse?.message ?? meResponse?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      plans = (plansResponse.data as List<dynamic>)
          .map((e) => PlanModel.fromJson(e as Map<String, dynamic>))
          .toList();

      final vendorMe = VendorMeModel.fromJson(meResponse.data as Map<String, dynamic>);
      vendorId = vendorMe.id;
      isRenewal = vendorMe.status != null && vendorMe.status != 'draft';

      selectedPlanId ??= plans.isEmpty ? null : plans.first.id;
    } catch (e) {
      if (kDebugMode) {
        print('Fetch plans error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      update();
    }
  }

  void selectPlan(int planId) {
    selectedPlanId = planId;
    update();
  }

  /// Onboarding has no dashboard to fall back on and the nav stack was
  /// cleared getting here, so this screen carries its own sign-out —
  /// same steps as VendorDashboardController.logoutAPI().
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

  PlanModel? get selectedPlan {
    for (final plan in plans) {
      if (plan.id == selectedPlanId) {
        return plan;
      }
    }
    return null;
  }

  void continueToServices() {
    final plan = selectedPlan;

    if (plan == null) {
      Utils.showToast(tr(StringRes.selectAPlanFirst), isError: true);
      return;
    }

    if (vendorId == null) {
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
      return;
    }

    Get.to(() => VendorSelectServicesView(vendorId: vendorId!, plan: plan, isRenewal: isRenewal));
  }
}
