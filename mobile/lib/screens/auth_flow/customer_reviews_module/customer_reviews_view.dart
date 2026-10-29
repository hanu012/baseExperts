import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_detail_module/vendor_detail_view.dart';
import 'customer_reviews_controller.dart';

/// Profile → My reviews.
class CustomerReviewsView extends StatelessWidget {
  const CustomerReviewsView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerReviewsController>(
      init: CustomerReviewsController(),
      dispose: (_) => Get.delete<CustomerReviewsController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          appBar: AppBar(
            backgroundColor: ServiceTokens.bg,
            surfaceTintColor: Colors.transparent,
            elevation: 0,
            title: BaseTextDMSans(
              text: StringRes.myReviewsTitle,
              fontSize: 18,
              fontWeight: FontWeight.w800,
              color: ServiceTokens.text,
            ).tr(),
          ),
          body: body(controller),
        );
      },
    );
  }

  Widget body(CustomerReviewsController controller) {
    if (controller.isLoading && controller.reviews.isEmpty) {
      return Center(child: CupertinoActivityIndicator(color: ColorRes.primaryColor));
    }

    if (controller.reviews.isEmpty) {
      return Center(
        child: Padding(
          padding: EdgeInsets.all(24.getSize),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.star_border, size: 40.getSize, color: ServiceTokens.muted),
              12.heightSpacer,
              BaseTextDMSans(
                text: StringRes.noReviewsWritten,
                fontSize: 16,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.text,
              ).tr(),
              4.heightSpacer,
              BaseTextDMSans(
                text: StringRes.noReviewsWrittenDesc,
                fontSize: 12,
                color: ServiceTokens.muted,
              ).tr(),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      color: ColorRes.primaryColor,
      onRefresh: controller.fetchAPI,
      child: ListView.separated(
        padding: EdgeInsets.all(16.getSize),
        itemCount: controller.reviews.length + (controller.hasMore ? 1 : 0),
        separatorBuilder: (_, _) => 12.heightSpacer,
        itemBuilder: (_, index) {
          if (index == controller.reviews.length) {
            return loadMoreButton(controller);
          }

          return reviewCard(controller.reviews[index]);
        },
      ),
    );
  }

  Widget loadMoreButton(CustomerReviewsController controller) {
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

  Widget reviewCard(CustomerReviewModel review) {
    final when = DateTime.tryParse(review.createdAt ?? '')?.toLocal();

    return InkWell(
      borderRadius: BorderRadius.circular(14.getSize),
      onTap: review.vendorId == null
          ? null
          : () => Get.to(
                () => VendorDetailView(
                  vendorId: review.vendorId!,
                  subcategoryId: review.subcategoryId,
                  zoneId: null,
                  latitude: Injector.customerLatitude,
                  longitude: Injector.customerLongitude,
                ),
              ),
      child: Container(
        padding: EdgeInsets.all(12.getSize),
        decoration: BoxDecoration(
          color: ServiceTokens.card,
          borderRadius: BorderRadius.circular(14.getSize),
          border: Border.all(color: ServiceTokens.stroke),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: BaseTextDMSans(
                    text: review.vendorName ?? '-',
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: ServiceTokens.text,
                    textAlign: TextAlign.start,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                8.widthSpacer,
                VendorDetailView.starRow(review.rating ?? 0),
              ],
            ),
            2.heightSpacer,
            BaseTextDMSans(
              text: [
                review.subcategoryName,
                if (when != null) DateFormat('dd MMM yyyy').format(when),
              ].whereType<String>().join(' · '),
              fontSize: 12,
              color: ServiceTokens.muted,
              textAlign: TextAlign.start,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
            if (review.comment != null && review.comment!.isNotEmpty) ...[
              8.heightSpacer,
              BaseTextDMSans(
                text: review.comment!,
                fontSize: 13,
                color: ServiceTokens.text,
                textAlign: TextAlign.start,
              ),
            ],
            if (review.isHidden) ...[
              8.heightSpacer,
              BaseTextDMSans(
                text: StringRes.hiddenByAdminLabel,
                fontSize: 11,
                fontWeight: FontWeight.w600,
                color: ColorRes.warningColor,
                textAlign: TextAlign.start,
              ).tr(),
            ],
            if (review.vendorReply != null) ...[
              10.heightSpacer,
              Container(
                width: double.infinity,
                padding: EdgeInsets.all(10.getSize),
                decoration: BoxDecoration(
                  color: ServiceTokens.bg,
                  borderRadius: BorderRadius.circular(10.getSize),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    BaseTextDMSans(
                      text: StringRes.vendorReplyLabel,
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      color: ColorRes.primaryColor,
                    ).tr(),
                    4.heightSpacer,
                    BaseTextDMSans(
                      text: review.vendorReply!,
                      fontSize: 12,
                      color: ServiceTokens.muted,
                      textAlign: TextAlign.start,
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
