import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_payment_pending_controller.dart';

/// "Waiting for payment" — see [VendorPaymentPendingController].
class VendorPaymentPendingView extends StatelessWidget {
  const VendorPaymentPendingView({super.key, required this.pendingPayment});

  final PendingPaymentModel pendingPayment;

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorPaymentPendingController>(
      init: VendorPaymentPendingController(pendingPayment: pendingPayment),
      dispose: (_) => Get.delete<VendorPaymentPendingController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: RefreshIndicator(
            color: ServiceTokens.accent,
            onRefresh: controller.checkAgainAPI,
            child: ListView(
              padding: EdgeInsets.zero,
              children: [
                VendorHero(
                  child: VendorHeroTitle(
                    title: tr(StringRes.paymentPendingTitle),
                    subtitle: tr(StringRes.paymentLinkSentVendor),
                  ),
                ),
                Padding(
                  padding: EdgeInsets.all(16.getSize),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      summaryCard(controller),
                      24.heightSpacer,
                      VendorPrimaryButton(
                        label: tr(StringRes.checkPaymentStatus),
                        icon: Icons.refresh,
                        enabled: !controller.isChecking,
                        onTap: controller.checkAgainAPI,
                      ),
                      12.heightSpacer,
                      VendorSecondaryButton(
                        label: tr(StringRes.chooseDifferentPlan),
                        onTap: controller.chooseDifferentPlan,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget summaryCard(VendorPaymentPendingController controller) {
    final payment = controller.pendingPayment;

    Widget row(String label, String value) {
      return Padding(
        padding: EdgeInsets.symmetric(vertical: 6.getSize),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            BaseTextDMSans(text: label, fontSize: 12, color: ServiceTokens.muted),
            12.widthSpacer,
            Flexible(
              child: BaseTextDMSans(
                text: value,
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.text,
                textAlign: TextAlign.end,
              ),
            ),
          ],
        ),
      );
    }

    return VendorPanel(
      child: Column(
        children: [
          row(tr(StringRes.planLabel), payment.planName ?? '-'),
          row(tr(StringRes.priceLabel), Utils.rupeesWhole(payment.amountPaise ?? 0)),
          if (controller.expiresLabel.isNotEmpty)
            row(tr(StringRes.paymentLinkValidUntil), controller.expiresLabel),
        ],
      ),
    );
  }
}
