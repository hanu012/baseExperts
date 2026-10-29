import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../select_services_module/select_services_view.dart';

/// Add Vendor, step 2a — plan selection (SPEC section 2.2).
///
/// Plan choice gates everything on the next screen: its quota
/// (max_categories/max_subcategories/max_zones) is what drives the live
/// "X of Y selected" counters there. This screen's own job stops at picking
/// one plan and handing it forward.
class SelectPlanController extends GetxController {
  final int vendorId;
  final String businessName;
  final String loginEmail;
  final bool isRenewal;

  SelectPlanController({
    required this.vendorId,
    required this.businessName,
    required this.loginEmail,
    this.isRenewal = false,
  });

  List<PlanModel> plans = [];
  int? selectedPlanId;
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
      final response = await DataSource.instance.plansAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      plans = (response.data as List<dynamic>)
          .map((e) => PlanModel.fromJson(e as Map<String, dynamic>))
          .toList();

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

    Get.to(() => SelectServicesView(
          vendorId: vendorId,
          plan: plan,
          businessName: businessName,
          loginEmail: loginEmail,
          isRenewal: isRenewal,
        ));
  }
}
