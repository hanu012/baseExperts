import '../../../constants/app.export.dart';
import '../vendor_dashboard_module/vendor_dashboard_view.dart';
import '../vendor_payment_pending_module/vendor_payment_pending_view.dart';
import '../vendor_select_plan_module/vendor_select_plan_view.dart';

/// The single "check subscription, then land somewhere" gate every
/// post-auth vendor entry point routes through (login, already-logged-in
/// app boot, immediate post-register) — SPEC section 3.2: has_active_subscription
/// decides dashboard vs plan-selection. Kept as one shared screen rather
/// than duplicating the check at each entry point.
class VendorLandingController extends GetxController {
  bool hasError = false;

  @override
  void onInit() {
    super.onInit();
    checkStatusAPI();
  }

  Future<void> checkStatusAPI() async {
    hasError = false;
    update();

    try {
      final response = await DataSource.instance.vendorMeAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        hasError = true;
        update();
        return;
      }

      final vendorMe = VendorMeModel.fromJson(response.data as Map<String, dynamic>);

      final pendingPayment = vendorMe.pendingPayment;

      if (vendorMe.hasActiveSubscription) {
        Utils.transitionWithOffAll(const VendorDashboardView());
      } else if (pendingPayment != null) {
        // Subscribed online, payment link not paid yet (Razorpay on).
        Utils.transitionWithOffAll(VendorPaymentPendingView(pendingPayment: pendingPayment));
      } else {
        Utils.transitionWithOffAll(const VendorSelectPlanView());
      }
    } catch (e) {
      if (kDebugMode) {
        print('Vendor landing check error $e');
      }
      hasError = true;
      update();
    }
  }
}
