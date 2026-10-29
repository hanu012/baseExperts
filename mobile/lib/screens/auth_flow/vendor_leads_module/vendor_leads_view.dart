import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_leads_controller.dart';

/// The vendor Leads tab (SPEC section 3 item 7, task 4.8) — its own
/// module/fetch, matching Portfolio's precedent for genuinely
/// independent data rather than sharing the dashboard's single
/// GET /vendors/me fetch.
class VendorLeadsView extends StatelessWidget {
  const VendorLeadsView({super.key, this.standalone = false});

  /// Opened on its own (home → Recent calls → See all) rather than as a
  /// dashboard tab body, so it brings its own page and back arrow.
  final bool standalone;

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorLeadsController>(
      init: VendorLeadsController(),
      dispose: (_) => Get.delete<VendorLeadsController>(),
      builder: (controller) {
        if (!standalone) {
          return body(controller);
        }

        return Scaffold(
          backgroundColor: ColorRes.backgroundColor,
          appBar: AppBar(
            title: BaseTextDMSans(
              text: tr(StringRes.leadsTab),
              fontSize: 16,
              fontWeight: FontWeight.w800,
              color: ColorRes.secondaryColor,
            ),
          ),
          body: body(controller),
        );
      },
    );
  }

  Widget body(VendorLeadsController controller) {
    if (controller.isLoading && controller.leads.isEmpty) {
      return Center(child: CupertinoActivityIndicator(color: ColorRes.primaryColor));
    }

    if (controller.leads.isEmpty) {
      return Center(
        child: BaseTextDMSans(
          text: StringRes.noLeadsYet,
          fontSize: 12,
          color: ColorRes.grayColor,
        ).tr(),
      );
    }

    return RefreshIndicator(
      onRefresh: controller.fetchAPI,
      color: ColorRes.primaryColor,
      child: ListView.separated(
        padding: EdgeInsets.all(16.getSize),
        itemCount: controller.leads.length + (controller.hasMore ? 1 : 0),
        separatorBuilder: (_, _) => 12.heightSpacer,
        itemBuilder: (_, index) {
          if (index == controller.leads.length) {
            return loadMoreButton(controller);
          }

          return leadCard(controller, controller.leads[index]);
        },
      ),
    );
  }

  Widget loadMoreButton(VendorLeadsController controller) {
    return Center(
      child: controller.isLoadingMore
          ? CupertinoActivityIndicator(color: ColorRes.primaryColor)
          : TextButton(
              onPressed: () => controller.fetchAPI(loadMore: true),
              child: BaseTextDMSans(
                text: StringRes.loadMore,
                fontSize: 12,
                color: ColorRes.primaryColor,
              ).tr(),
            ),
    );
  }

  Widget leadCard(VendorLeadsController controller, LeadModel lead) {
    return Container(
      padding: EdgeInsets.all(12.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceElevatedColor,
        borderRadius: BorderRadius.circular(10.getSize),
        border: Border.all(color: ColorRes.borderColor),
      ),
      child: Row(
        children: [
          Icon(
            lead.channel == 'whatsapp' ? Icons.chat : Icons.call,
            color: ColorRes.primaryColor,
            size: 20.getSize,
          ),
          12.widthSpacer,
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                BaseTextDMSans(
                  text: lead.customerName ?? '',
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: ColorRes.secondaryColor,
                ),
                4.heightSpacer,
                BaseTextDMSans(
                  text: lead.subcategoryName ?? '',
                  fontSize: 12,
                  color: ColorRes.grayColor,
                ),
                if (lead.createdAt != null) ...[
                  2.heightSpacer,
                  BaseTextDMSans(
                    text: [
                      lead.createdAt!.split('T').first,
                      if (lead.callDurationSeconds != null) Utils.approxCallLength(lead.callDurationSeconds),
                    ].join(' · '),
                    fontSize: 10,
                    color: ColorRes.grayColor,
                  ),
                ],
              ],
            ),
          ),
          8.widthSpacer,
          requestReviewButton(controller, lead),
        ],
      ),
    );
  }

  Widget requestReviewButton(VendorLeadsController controller, LeadModel lead) {
    if (controller.requestingReviewForLeadId == lead.id) {
      return CupertinoActivityIndicator(color: ColorRes.primaryColor);
    }

    if (lead.hasReview) {
      return BaseTextDMSans(
        text: StringRes.reviewAlreadyLeft,
        fontSize: 10,
        color: ColorRes.grayColor,
      ).tr();
    }

    if (lead.reviewRequestedAt != null) {
      return BaseTextDMSans(
        text: StringRes.reviewAlreadyRequested,
        fontSize: 10,
        color: ColorRes.grayColor,
      ).tr();
    }

    return TextButton(
      onPressed: () => controller.requestReviewAPI(lead),
      child: BaseTextDMSans(
        text: StringRes.requestReviewButton,
        fontSize: 12,
        color: ColorRes.primaryColor,
      ).tr(),
    );
  }
}
