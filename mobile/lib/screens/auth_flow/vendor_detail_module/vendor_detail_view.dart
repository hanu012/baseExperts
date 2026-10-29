import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_detail_controller.dart';

/// Vendor detail page (SPEC section 4 item 6, task 5.4/5.5) — the screen
/// the search-results list (task 5.3) leads into.
class VendorDetailView extends StatelessWidget {
  final int vendorId;

  /// Nullable — see VendorDetailController's own docblock. The
  /// favorites list (task: favorites/share/report/account deletion)
  /// has no subcategory context to pass.
  final int? subcategoryId;
  final int? zoneId;
  final double? latitude;
  final double? longitude;

  const VendorDetailView({
    super.key,
    required this.vendorId,
    required this.subcategoryId,
    required this.zoneId,
    required this.latitude,
    required this.longitude,
  });

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorDetailController>(
      init: VendorDetailController(
        vendorId: vendorId,
        subcategoryId: subcategoryId,
        zoneId: zoneId,
        latitude: latitude,
        longitude: longitude,
      ),
      dispose: (_) => Get.delete<VendorDetailController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ColorRes.backgroundColor,
          appBar: AppBar(
            title: BaseTextDMSans(
              text: controller.vendor?.businessName ?? '',
              fontSize: 18,
              fontWeight: FontWeight.w600,
              color: ColorRes.secondaryColor,
            ),
            actions: controller.vendor == null ? null : appBarActions(controller),
          ),
          body: body(controller),
          bottomNavigationBar: controller.vendor == null ? null : actionBar(controller),
        );
      },
    );
  }

  List<Widget> appBarActions(VendorDetailController controller) {
    final vendor = controller.vendor!;

    return [
      IconButton(
        onPressed: controller.toggleFavoriteAPI,
        icon: Icon(
          vendor.isFavorite ? Icons.favorite : Icons.favorite_border,
          color: vendor.isFavorite ? ColorRes.errorColor : ColorRes.secondaryColor,
          size: 22.getSize,
        ),
      ),
      IconButton(
        onPressed: controller.shareProfile,
        icon: Icon(Icons.share, color: ColorRes.secondaryColor, size: 20.getSize),
      ),
      PopupMenuButton<void>(
        icon: Icon(Icons.more_vert, color: ColorRes.secondaryColor, size: 22.getSize),
        color: ColorRes.surfaceElevatedColor,
        onSelected: (_) => _showReportVendorSheet(controller),
        itemBuilder: (_) => [
          PopupMenuItem(
            child: BaseTextDMSans(
              text: StringRes.reportVendorMenuItem,
              fontSize: 12,
              color: ColorRes.errorColor,
            ).tr(),
          ),
        ],
      ),
    ];
  }

  void _showReportVendorSheet(VendorDetailController controller) {
    showModalBottomSheet(
      context: Get.context!,
      backgroundColor: ColorRes.surfaceColor,
      isScrollControlled: true,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16.getSize)),
      ),
      builder: (_) => _ReportVendorSheet(controller: controller),
    );
  }

  Widget body(VendorDetailController controller) {
    if (controller.isLoading && controller.vendor == null) {
      return Center(child: CupertinoActivityIndicator(color: ColorRes.primaryColor));
    }

    final vendor = controller.vendor;

    if (vendor == null) {
      return Center(
        child: BaseTextDMSans(
          text: StringRes.vendorNotFound,
          fontSize: 12,
          color: ColorRes.grayColor,
        ).tr(),
      );
    }

    return SingleChildScrollView(
      padding: EdgeInsets.all(16.getSize),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          shopPhoto(vendor),
          16.heightSpacer,
          BaseTextDMSans(
            text: vendor.businessName ?? '',
            fontSize: 18,
            fontWeight: FontWeight.w600,
            color: ColorRes.secondaryColor,
          ),
          6.heightSpacer,
          ratingAndDistanceRow(vendor),
          if (vendor.address != null) ...[
            8.heightSpacer,
            BaseTextDMSans(text: vendor.address!, fontSize: 12, color: ColorRes.grayColor),
          ],
          24.heightSpacer,
          sectionTitle(StringRes.servicesOfferedSection),
          10.heightSpacer,
          servicesWrap(vendor),
          24.heightSpacer,
          workGallery(controller),
          24.heightSpacer,
          reviewsSectionHeader(controller),
          10.heightSpacer,
          reviewsList(vendor),
          // Room for the fixed action bar at the bottom.
          80.heightSpacer,
        ],
      ),
    );
  }

  Widget reviewsSectionHeader(VendorDetailController controller) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        sectionTitle(StringRes.reviewsSectionTitle),
        TextButton(
          onPressed: () => _showWriteReviewSheet(controller),
          child: BaseTextDMSans(
            text: StringRes.writeReviewButton,
            fontSize: 12,
            color: ColorRes.primaryColor,
          ).tr(),
        ),
      ],
    );
  }

  Widget reviewsList(VendorDetailModel vendor) {
    if (vendor.reviews.isEmpty) {
      return Padding(
        padding: EdgeInsets.symmetric(vertical: 12.getSize),
        child: BaseTextDMSans(
          text: StringRes.noReviewsYet,
          fontSize: 12,
          color: ColorRes.grayColor,
        ).tr(),
      );
    }

    return Column(
      children: [
        for (final review in vendor.reviews) ...[
          reviewCard(review),
          12.heightSpacer,
        ],
      ],
    );
  }

  Widget reviewCard(ReviewModel review) {
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
              starRow(review.rating ?? 0),
            ],
          ),
          if (review.comment != null && review.comment!.isNotEmpty) ...[
            6.heightSpacer,
            BaseTextDMSans(text: review.comment!, fontSize: 12, color: ColorRes.grayColor),
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
                    text: StringRes.vendorReplyLabel,
                    fontSize: 10,
                    fontWeight: FontWeight.w600,
                    color: ColorRes.primaryColor,
                  ).tr(),
                  4.heightSpacer,
                  BaseTextDMSans(
                    text: review.vendorReply!,
                    fontSize: 12,
                    color: ColorRes.grayColor,
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  static Widget starRow(int rating, {double size = 14, ValueChanged<int>? onTap}) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var star = 1; star <= 5; star++)
          GestureDetector(
            onTap: onTap == null ? null : () => onTap(star),
            child: Icon(
              star <= rating ? Icons.star : Icons.star_border,
              color: ColorRes.warningColor,
              size: size.getSize,
            ),
          ),
      ],
    );
  }

  void _showWriteReviewSheet(VendorDetailController controller) {
    showModalBottomSheet(
      context: Get.context!,
      backgroundColor: ColorRes.surfaceColor,
      isScrollControlled: true,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16.getSize)),
      ),
      builder: (_) => _WriteReviewSheet(controller: controller),
    );
  }

  Widget shopPhoto(VendorDetailModel vendor) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(12.getSize),
      child: AspectRatio(
        aspectRatio: 16 / 9,
        child: vendor.shopPhotoUrl != null
            ? Image.network(
                vendor.shopPhotoUrl!,
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) => Container(
                  color: ColorRes.surfaceElevatedColor,
                  child: Icon(Icons.storefront, color: ColorRes.grayColor, size: 40.getSize),
                ),
              )
            : Container(
                color: ColorRes.surfaceElevatedColor,
                child: Icon(Icons.storefront, color: ColorRes.grayColor, size: 40.getSize),
              ),
      ),
    );
  }

  Widget ratingAndDistanceRow(VendorDetailModel vendor) {
    return Row(
      children: [
        if ((vendor.ratingCount ?? 0) > 0) ...[
          Icon(Icons.star, color: ColorRes.warningColor, size: 16.getSize),
          4.widthSpacer,
          BaseTextDMSans(
            text: '${vendor.ratingAvg?.toStringAsFixed(1)} (${vendor.ratingCount})',
            fontSize: 12,
            color: ColorRes.grayColor,
          ),
        ] else
          BaseTextDMSans(
            text: StringRes.newVendorLabel,
            fontSize: 12,
            color: ColorRes.primaryColor,
          ).tr(),
        if (vendor.distanceKm != null) ...[
          12.widthSpacer,
          Icon(Icons.location_on, color: ColorRes.grayColor, size: 16.getSize),
          4.widthSpacer,
          BaseTextDMSans(
            text: '${vendor.distanceKm!.toStringAsFixed(1)} ${tr(StringRes.distanceAwayLabel)}',
            fontSize: 12,
            color: ColorRes.grayColor,
          ),
        ],
      ],
    );
  }

  Widget sectionTitle(String labelKey) {
    return BaseTextDMSans(
      text: labelKey,
      fontSize: 14,
      fontWeight: FontWeight.w600,
      color: ColorRes.secondaryColor,
    ).tr();
  }

  Widget servicesWrap(VendorDetailModel vendor) {
    if (vendor.services.isEmpty) {
      return const SizedBox.shrink();
    }

    return Wrap(
      spacing: 8.getSize,
      runSpacing: 8.getSize,
      children: [
        for (final service in vendor.services)
          Container(
            padding: EdgeInsets.symmetric(horizontal: 12.getSize, vertical: 8.getSize),
            decoration: BoxDecoration(
              color: ColorRes.surfaceElevatedColor,
              borderRadius: BorderRadius.circular(20.getSize),
              border: Border.all(color: ColorRes.borderColor),
            ),
            child: BaseTextDMSans(
              text: service.name ?? '',
              fontSize: 12,
              fontWeight: FontWeight.w500,
              color: ColorRes.secondaryColor,
            ),
          ),
      ],
    );
  }

  /// "Photos & videos", grouped by service.
  ///
  /// Filter chips — All, then one per service that has work — over a
  /// three-column grid. Tapping a tile opens a full-screen viewer that
  /// swipes through the filtered set and names the service on each item,
  /// so a customer looking for gas filling can check this vendor's gas
  /// filling work rather than scroll a wall of everything.
  Widget workGallery(VendorDetailController controller) {
    final vendor = controller.vendor!;
    final services = controller.galleryServices;
    final items = controller.galleryMedia;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(child: sectionTitle(StringRes.photosVideosSection)),
            if (vendor.media.isNotEmpty)
              BaseTextDMSans(
                text: '${vendor.media.length}',
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: ColorRes.grayColor,
              ),
          ],
        ),
        if (vendor.media.isEmpty)
          Padding(
            padding: EdgeInsets.symmetric(vertical: 12.getSize),
            child: BaseTextDMSans(
              text: StringRes.noPortfolioYet,
              fontSize: 12,
              color: ColorRes.grayColor,
            ).tr(),
          )
        else ...[
          // Chips only help when there is more than one service to pick.
          if (services.length > 1) ...[
            4.heightSpacer,
            BaseTextDMSans(
              text: StringRes.workGalleryHint,
              fontSize: 12,
              color: ColorRes.grayColor,
            ).tr(),
            10.heightSpacer,
            galleryChips(controller, services),
          ],
          12.heightSpacer,
          GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            padding: EdgeInsets.zero,
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 3,
              crossAxisSpacing: 8.getSize,
              mainAxisSpacing: 8.getSize,
            ),
            itemCount: items.length,
            itemBuilder: (_, index) {
              final item = items[index];

              return PortfolioMediaTile(
                type: item.type,
                url: item.url,
                radius: 10,
                onTap: () => controller.openGalleryItem(item),
                // Under "All", each tile names its service; once filtered
                // the chip already says it.
                badge: controller.gallerySubcategoryId == null && services.length > 1
                    ? serviceBadge(item.subcategoryName)
                    : null,
              );
            },
          ),
        ],
      ],
    );
  }

  Widget galleryChips(VendorDetailController controller, List<VendorServiceModel> services) {
    Widget chip({required String label, required int count, required bool selected, required VoidCallback onTap}) {
      return Padding(
        padding: EdgeInsets.only(right: 8.getSize),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(20.getSize),
          child: Container(
            padding: EdgeInsets.symmetric(horizontal: 12.getSize, vertical: 8.getSize),
            decoration: BoxDecoration(
              color: selected ? ColorRes.primaryColor : ColorRes.surfaceElevatedColor,
              borderRadius: BorderRadius.circular(20.getSize),
              border: Border.all(color: selected ? ColorRes.primaryColor : ColorRes.borderColor),
            ),
            child: BaseTextDMSans(
              text: '$label · $count',
              fontSize: 12,
              fontWeight: FontWeight.w600,
              // White only on the filled primary chip.
              color: selected ? ColorRes.whiteColor : ColorRes.secondaryColor,
            ),
          ),
        ),
      );
    }

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          chip(
            label: tr(StringRes.galleryAllLabel),
            count: controller.vendor!.media.length,
            selected: controller.gallerySubcategoryId == null,
            onTap: () => controller.selectGalleryService(null),
          ),
          for (final service in services)
            chip(
              label: service.name ?? '',
              count: controller.galleryCount(service.subcategoryId),
              selected: controller.gallerySubcategoryId == service.subcategoryId,
              onTap: () => controller.selectGalleryService(service.subcategoryId),
            ),
        ],
      ),
    );
  }

  Widget? serviceBadge(String? name) {
    if (name == null || name.isEmpty) {
      return null;
    }

    return Container(
      padding: EdgeInsets.symmetric(horizontal: 6.getSize, vertical: 3.getSize),
      decoration: BoxDecoration(
        color: Colors.black.withValues(alpha: 0.55),
        borderRadius: BorderRadius.circular(6.getSize),
      ),
      child: BaseTextDMSans(
        text: name,
        fontSize: 10,
        fontWeight: FontWeight.w600,
        // On the dark scrim, not on a card.
        color: Colors.white,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
    );
  }

  Widget actionBar(VendorDetailController controller) {
    return Container(
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceColor,
        border: Border(top: BorderSide(color: ColorRes.borderColor)),
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            Expanded(
              child: BaseRaisedButton(
                onPressed: controller.isSubmittingLead ? null : controller.callAPI,
                buttonText: StringRes.callButton,
                buttonColor: ColorRes.primaryColor,
                icon: Icon(Icons.call, color: ColorRes.backgroundColor, size: 18.getSize),
              ),
            ),
            8.widthSpacer,
            Expanded(
              child: BaseRaisedButton(
                onPressed: controller.isSubmittingLead ? null : controller.whatsappAPI,
                buttonText: StringRes.whatsappButton,
                buttonColor: ColorRes.surfaceElevatedColor,
                textColor: ColorRes.secondaryColor,
                icon: Icon(Icons.chat, color: ColorRes.secondaryColor, size: 18.getSize),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The "Write a review" form (SPEC section 9, task 5.5) — a star picker
/// plus an optional comment, submitting through
/// [VendorDetailController.submitReviewAPI]. Kept as a private widget in
/// this file rather than its own module: it's a form over one screen's
/// controller, not a screen of its own, and nothing else in the app
/// needs it.
class _WriteReviewSheet extends StatefulWidget {
  final VendorDetailController controller;

  const _WriteReviewSheet({required this.controller});

  @override
  State<_WriteReviewSheet> createState() => _WriteReviewSheetState();
}

class _WriteReviewSheetState extends State<_WriteReviewSheet> {
  int _rating = 0;
  final _commentController = TextEditingController();

  @override
  void dispose() {
    _commentController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_rating == 0) {
      Utils.showToast(tr(StringRes.selectARatingFirst), isError: true);
      return;
    }

    final succeeded = await widget.controller.submitReviewAPI(
      rating: _rating,
      comment: _commentController.text.trim().isEmpty ? null : _commentController.text.trim(),
    );

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
      // No `init:` here — the controller is already registered by the
      // parent screen's GetBuilder; this just listens to that same
      // instance, so submitReviewAPI()'s update() calls (and its
      // fetchAPI() re-run on success) are reflected both here and on
      // the screen behind the sheet.
      child: GetBuilder<VendorDetailController>(
        builder: (controller) {
          return Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              BaseTextDMSans(
                text: StringRes.writeReviewButton,
                fontSize: 16,
                fontWeight: FontWeight.w600,
                color: ColorRes.secondaryColor,
              ).tr(),
              16.heightSpacer,
              BaseTextDMSans(
                text: StringRes.ratingLabel,
                fontSize: 12,
                color: ColorRes.grayColor,
              ).tr(),
              8.heightSpacer,
              VendorDetailView.starRow(
                _rating,
                size: 28,
                onTap: (star) => setState(() => _rating = star),
              ),
              16.heightSpacer,
              BaseTextField(
                controller: _commentController,
                hintText: tr(StringRes.reviewCommentHint),
                isShowBorder: true,
                maxLines: 3,
              ),
              16.heightSpacer,
              SizedBox(
                width: double.infinity,
                child: BaseRaisedButton(
                  onPressed: controller.isSubmittingReview ? null : _submit,
                  buttonText: StringRes.submitReview,
                  buttonColor: ColorRes.primaryColor,
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

/// "Report this vendor" form (SPEC section 4 item 10 / section 5.15) —
/// minimal, a reason field and a submit button, no ticket lifecycle UI.
/// Same private-widget-over-the-parent-screen's-controller shape as
/// [_WriteReviewSheet] above.
class _ReportVendorSheet extends StatefulWidget {
  final VendorDetailController controller;

  const _ReportVendorSheet({required this.controller});

  @override
  State<_ReportVendorSheet> createState() => _ReportVendorSheetState();
}

class _ReportVendorSheetState extends State<_ReportVendorSheet> {
  final _reasonController = TextEditingController();

  @override
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final reason = _reasonController.text.trim();

    if (reason.isEmpty) {
      Utils.showToast(tr(StringRes.selectAReasonFirst), isError: true);
      return;
    }

    final succeeded = await widget.controller.reportVendorAPI(reason);

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
      // No `init:` — reuses the parent screen's already-registered
      // VendorDetailController instance, same reasoning as
      // _WriteReviewSheet.
      child: GetBuilder<VendorDetailController>(
        builder: (controller) {
          return Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              BaseTextDMSans(
                text: StringRes.reportVendorTitle,
                fontSize: 16,
                fontWeight: FontWeight.w600,
                color: ColorRes.secondaryColor,
              ).tr(),
              16.heightSpacer,
              BaseTextField(
                controller: _reasonController,
                hintText: tr(StringRes.reportVendorReasonHint),
                isShowBorder: true,
                maxLines: 3,
              ),
              16.heightSpacer,
              SizedBox(
                width: double.infinity,
                child: BaseRaisedButton(
                  onPressed: controller.isSubmittingReport ? null : _submit,
                  buttonText: StringRes.reportVendorSubmitButton,
                  buttonColor: ColorRes.errorColor,
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
