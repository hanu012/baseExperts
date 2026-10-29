import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_detail_module/vendor_detail_view.dart';
import 'vendor_reviews_controller.dart';

/// The vendor's own Reviews tab (SPEC section 3 item 8, task 4.8) — its
/// own module/fetch, same "genuinely independent data" precedent
/// Portfolio and Leads already follow.
class VendorReviewsView extends StatelessWidget {
  const VendorReviewsView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorReviewsController>(
      init: VendorReviewsController(),
      dispose: (_) => Get.delete<VendorReviewsController>(),
      builder: (controller) {
        // The header is what keeps the list out from under the status bar
        // (VendorHero pads for it), and gives this tab the same teal top
        // as the dashboard's other tabs, so the white status-bar icons
        // stay readable here too.
        return Column(
          children: [
            VendorHero(child: VendorHeroTitle(title: tr(StringRes.reviewsTab))),
            Expanded(child: body(controller)),
          ],
        );
      },
    );
  }

  Widget body(VendorReviewsController controller) {
    if (controller.isLoading && controller.reviews.isEmpty) {
      return Center(child: CupertinoActivityIndicator(color: ColorRes.primaryColor));
    }

    if (controller.reviews.isEmpty) {
      return Center(
        child: BaseTextDMSans(
          text: StringRes.noReviewsYetVendor,
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
        itemCount: controller.reviews.length + (controller.hasMore ? 1 : 0),
        separatorBuilder: (_, _) => 12.heightSpacer,
        itemBuilder: (_, index) {
          if (index == controller.reviews.length) {
            return loadMoreButton(controller);
          }

          return reviewCard(controller, controller.reviews[index]);
        },
      ),
    );
  }

  Widget loadMoreButton(VendorReviewsController controller) {
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

  Widget reviewCard(VendorReviewsController controller, VendorReviewModel review) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(12.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceElevatedColor,
        borderRadius: BorderRadius.circular(10.getSize),
        border: Border.all(color: ColorRes.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              BaseTextDMSans(
                text: review.customerName ?? '',
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: ColorRes.secondaryColor,
              ),
              VendorDetailView.starRow(review.rating ?? 0),
            ],
          ),
          if (review.comment != null && review.comment!.isNotEmpty) ...[
            6.heightSpacer,
            BaseTextDMSans(text: review.comment!, fontSize: 12, color: ColorRes.grayColor),
          ],
          if (review.isHidden) ...[
            8.heightSpacer,
            Container(
              padding: EdgeInsets.symmetric(horizontal: 8.getSize, vertical: 4.getSize),
              decoration: BoxDecoration(
                color: ColorRes.errorColor.withValues(alpha: 0.15),
                borderRadius: BorderRadius.circular(6.getSize),
              ),
              child: BaseTextDMSans(
                text: StringRes.hiddenByAdminLabel,
                fontSize: 10,
                color: ColorRes.errorColor,
              ).tr(),
            ),
          ],
          if (review.vendorReply != null) ...[
            10.heightSpacer,
            Container(
              padding: EdgeInsets.all(10.getSize),
              decoration: BoxDecoration(
                color: ColorRes.surfaceColor,
                borderRadius: BorderRadius.circular(8.getSize),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  BaseTextDMSans(
                    text: StringRes.yourReplyLabel,
                    fontSize: 10,
                    fontWeight: FontWeight.w600,
                    color: ColorRes.primaryColor,
                  ).tr(),
                  4.heightSpacer,
                  BaseTextDMSans(text: review.vendorReply!, fontSize: 12, color: ColorRes.grayColor),
                ],
              ),
            ),
          ],
          8.heightSpacer,
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(
              onPressed: () => _showReplySheet(controller, review),
              child: BaseTextDMSans(
                text: StringRes.replyButton,
                fontSize: 12,
                color: ColorRes.primaryColor,
              ).tr(),
            ),
          ),
        ],
      ),
    );
  }

  void _showReplySheet(VendorReviewsController controller, VendorReviewModel review) {
    showModalBottomSheet(
      context: Get.context!,
      backgroundColor: ColorRes.surfaceColor,
      isScrollControlled: true,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16.getSize)),
      ),
      builder: (_) => _ReplySheet(controller: controller, review: review),
    );
  }
}

/// The "Reply" form (SPEC section 4 item 9, task 5.5/4.8) — pre-filled
/// with any existing reply text, so replying again edits it rather than
/// starting blank (VendorReviewController::reply() already allows
/// overwriting an existing reply anytime).
class _ReplySheet extends StatefulWidget {
  final VendorReviewsController controller;
  final VendorReviewModel review;

  const _ReplySheet({required this.controller, required this.review});

  @override
  State<_ReplySheet> createState() => _ReplySheetState();
}

class _ReplySheetState extends State<_ReplySheet> {
  late final TextEditingController _replyController =
      TextEditingController(text: widget.review.vendorReply ?? '');

  @override
  void dispose() {
    _replyController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final reply = _replyController.text.trim();

    if (reply.isEmpty) {
      Utils.showToast(tr(StringRes.fieldRequired), isError: true);
      return;
    }

    final succeeded = await widget.controller.replyToReviewAPI(widget.review, reply);

    if (succeeded && mounted) {
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 16.getSize,
        right: 16.getSize,
        top: 16.getSize,
        bottom: 16.getSize + MediaQuery.of(context).viewInsets.bottom,
      ),
      // No `init:` — the controller is already registered by the parent
      // screen's GetBuilder; this just listens to the same instance.
      child: GetBuilder<VendorReviewsController>(
        builder: (controller) {
          return Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              BaseTextDMSans(
                text: StringRes.replyButton,
                fontSize: 16,
                fontWeight: FontWeight.w600,
                color: ColorRes.secondaryColor,
              ).tr(),
              16.heightSpacer,
              BaseTextField(
                controller: _replyController,
                hintText: tr(StringRes.reviewCommentHint),
                isShowBorder: true,
                maxLines: 3,
              ),
              16.heightSpacer,
              BaseRaisedButton(
                onPressed: controller.isSubmittingReply ? null : _submit,
                buttonText: StringRes.submitReply,
                buttonColor: ColorRes.primaryColor,
              ),
            ],
          );
        },
      ),
    );
  }
}
