import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'select_plan_controller.dart';

/// Add Vendor, step 2a — plan selection (SPEC section 2.2).
class SelectPlanView extends StatelessWidget {
  final int vendorId;
  final String businessName;
  final String loginEmail;

  /// Renewing a vendor who already had a plan (salesman vendor detail →
  /// Renew), rather than a first subscription.
  final bool isRenewal;

  const SelectPlanView({
    super.key,
    required this.vendorId,
    required this.businessName,
    required this.loginEmail,
    this.isRenewal = false,
  });

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SelectPlanController>(
      init: SelectPlanController(
        vendorId: vendorId,
        businessName: businessName,
        loginEmail: loginEmail,
        isRenewal: isRenewal,
      ),
      dispose: (_) => Get.delete<SelectPlanController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ColorRes.backgroundColor,
          bottomNavigationBar: getBottomButton(controller, context),
          body: Utils.authLayout(
            onSkipTap: null,
            title: StringRes.selectPlanTitle,
            desc: StringRes.selectPlanDesc,
            isLogin: false,
            isForCustomer: false,
            skipTap: true,
            contentWidget: mainBody(controller),
          ),
        );
      },
    );
  }

  Widget mainBody(SelectPlanController controller) {
    if (controller.isLoading && controller.plans.isEmpty) {
      return Padding(
        padding: EdgeInsets.symmetric(vertical: 40.getSize),
        child: Center(
          child: CupertinoActivityIndicator(color: ColorRes.primaryColor),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final plan in controller.plans) ...[
          planCard(controller, plan),
          16.heightSpacer,
        ],
      ],
    );
  }

  Widget planCard(SelectPlanController controller, PlanModel plan) {
    final selected = controller.selectedPlanId == plan.id;

    return InkWell(
      onTap: () => controller.selectPlan(plan.id!),
      borderRadius: BorderRadius.circular(12.getSize),
      child: Container(
        padding: EdgeInsets.all(16.getSize),
        decoration: BoxDecoration(
          color: ColorRes.surfaceElevatedColor,
          borderRadius: BorderRadius.circular(12.getSize),
          border: Border.all(
            color: selected ? ColorRes.primaryColor : ColorRes.borderColor,
            width: selected ? 2 : 1,
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: BaseTextDMSans(
                    text: plan.name ?? '',
                    fontSize: 16,
                    fontWeight: FontWeight.w600,
                    color: ColorRes.secondaryColor,
                    textAlign: TextAlign.start,
                  ),
                ),
                Icon(
                  selected
                      ? Icons.radio_button_checked
                      : Icons.radio_button_off,
                  color: selected ? ColorRes.primaryColor : ColorRes.grayColor,
                  size: 20.getSize,
                ),
              ],
            ),
            6.heightSpacer,
            BaseTextDMSans(
              text: '${Utils.rupeesWhole(plan.pricePaise ?? 0)} '
                  '(${plan.durationDays ?? 0} ${tr(StringRes.perDay)})',
              fontSize: 14,
              fontWeight: FontWeight.w500,
              color: ColorRes.primaryColor,
              textAlign: TextAlign.start,
            ),
            10.heightSpacer,
            Wrap(
              spacing: 8.getSize,
              runSpacing: 8.getSize,
              children: [
                quotaChip('${plan.maxCategories ?? 0} categories'),
                quotaChip('${plan.maxSubcategories ?? 0} subcategories'),
                quotaChip('${plan.maxZones ?? 0} zones'),
                quotaChip('${plan.maxPhotos ?? 0} photos'),
                quotaChip('${plan.maxVideos ?? 0} videos'),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget quotaChip(String label) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 10.getSize, vertical: 4.getSize),
      decoration: BoxDecoration(
        color: ColorRes.backgroundColor,
        borderRadius: BorderRadius.circular(20.getSize),
      ),
      child: BaseTextDMSans(
        text: label,
        fontSize: 10,
        color: ColorRes.grayColor,
      ),
    );
  }

  Widget getBottomButton(SelectPlanController controller, BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 24.getSize,
        right: 24.getSize,
        top: 10.getSize,
        bottom: MediaQuery.of(context).viewInsets.bottom + 10.getSize,
      ),
      child: BaseRaisedButton(
        onPressed: controller.continueToServices,
        buttonText: StringRes.continueLabel,
        buttonColor: ColorRes.primaryColor,
      ),
    );
  }
}
