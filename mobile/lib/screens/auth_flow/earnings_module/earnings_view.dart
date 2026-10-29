import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'earnings_controller.dart';

/// Salesman home, Earnings tab (SPEC section 2.4).
class EarningsView extends StatelessWidget {
  const EarningsView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<EarningsController>(
      init: EarningsController(),
      dispose: (_) => Get.delete<EarningsController>(),
      builder: (controller) {
        if (controller.isLoading && controller.summary == null) {
          return Center(
            child: CupertinoActivityIndicator(color: ColorRes.primaryColor),
          );
        }

        final summary = controller.summary;

        if (summary == null) {
          return const SizedBox.shrink();
        }

        return RefreshIndicator(
          onRefresh: controller.fetchCommissionsAPI,
          color: ColorRes.primaryColor,
          child: ListView(
            padding: EdgeInsets.all(16.getSize),
            children: [
              Row(
                children: [
                  Expanded(
                    child: summaryCard(
                      labelKey: StringRes.pendingCommission,
                      amountPaise: summary.pendingAmountPaise ?? 0,
                      count: summary.pendingCount ?? 0,
                      accent: ColorRes.grayColor,
                    ),
                  ),
                  12.widthSpacer,
                  Expanded(
                    child: summaryCard(
                      labelKey: StringRes.paidCommission,
                      amountPaise: summary.paidAmountPaise ?? 0,
                      count: summary.paidCount ?? 0,
                      accent: ColorRes.primaryColor,
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }

  Widget summaryCard({
    required String labelKey,
    required int amountPaise,
    required int count,
    required Color accent,
  }) {
    return Container(
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceElevatedColor,
        borderRadius: BorderRadius.circular(12.getSize),
        border: Border.all(color: accent),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          BaseTextDMSans(
            text: labelKey,
            fontSize: 12,
            color: ColorRes.grayColor,
          ).tr(),
          8.heightSpacer,
          BaseTextDMSans(
            text: Utils.rupees(amountPaise),
            fontSize: 18,
            fontWeight: FontWeight.w600,
            color: ColorRes.secondaryColor,
          ),
          4.heightSpacer,
          BaseTextDMSans(
            text: '$count ${tr(StringRes.commissionCount)}',
            fontSize: 10,
            color: ColorRes.grayColor,
          ),
        ],
      ),
    );
  }
}
