import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../vendor_dashboard_module/vendor_dashboard_view.dart';
import '../vendor_select_plan_module/vendor_select_plan_view.dart';

/// "Waiting for payment" — a vendor who subscribed online while Razorpay
/// payment links are switched on (admin Settings). The link reached them by
/// SMS; the plan activates when Razorpay tells the server it is paid, so
/// this screen only re-checks GET /vendors/me.
///
/// It deliberately has no "pay" button: the payment happens outside the app,
/// in the browser the SMS link opens.
class VendorPaymentPendingController extends GetxController {
  VendorPaymentPendingController({required this.pendingPayment});

  PendingPaymentModel pendingPayment;

  bool isChecking = false;

  /// After paying: active → dashboard; the sale gone (link expired or
  /// replaced) → plan selection; otherwise still waiting.
  Future<void> checkAgainAPI() async {
    if (isChecking) {
      return;
    }

    isChecking = true;
    update();

    try {
      final response = await DataSource.instance.vendorMeAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      final vendorMe = VendorMeModel.fromJson(response.data as Map<String, dynamic>);

      if (vendorMe.hasActiveSubscription) {
        Utils.transitionWithOffAll(const VendorDashboardView());
        return;
      }

      final stillPending = vendorMe.pendingPayment;

      if (stillPending == null) {
        Utils.transitionWithOffAll(const VendorSelectPlanView());
        return;
      }

      pendingPayment = stillPending;
      Utils.showToast(tr(StringRes.stillAwaitingPayment));
    } catch (e) {
      if (kDebugMode) {
        print('Payment status check error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isChecking = false;
      update();
    }
  }

  /// Subscribing again cancels this link server-side, so the vendor can
  /// never end up paying for two plans.
  void chooseDifferentPlan() {
    Utils.transitionWithOffAll(const VendorSelectPlanView());
  }

  /// "14 Oct 2026, 18:30" in the device's time zone; empty when unknown.
  String get expiresLabel {
    final parsed = DateTime.tryParse(pendingPayment.expiresAt ?? '');

    if (parsed == null) {
      return '';
    }

    return DateFormat('dd MMM yyyy, HH:mm').format(parsed.toLocal());
  }
}
