import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_portfolio_module/vendor_portfolio_controller.dart';
import '../vendor_portfolio_module/vendor_portfolio_view.dart';

/// Portfolio, step 3 of 3: one service's photos and videos, with the
/// upload buttons. Everything added here is filed under this service —
/// [VendorPortfolioController.openService] selected it on the way in.
///
/// No controller of its own, for the same reason as
/// VendorPortfolioServicesView: it listens to the Portfolio tab's
/// controller, which owns the upload and stays registered underneath.
class VendorPortfolioGalleryView extends StatelessWidget {
  const VendorPortfolioGalleryView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorPortfolioController>(
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: RefreshIndicator(
            onRefresh: controller.fetchPortfolioAPI,
            color: ServiceTokens.accentBright,
            backgroundColor: ServiceTokens.card,
            child: ListView(
              padding: EdgeInsets.zero,
              children: [
                hero(controller),
                Transform.translate(
                  offset: Offset(0, -34.getSize),
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(16.getSize, 0, 16.getSize, 24.getSize),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        VendorPortfolioView.quotaRow(controller),
                        12.heightSpacer,
                        VendorPortfolioView.liveNote(),
                        18.heightSpacer,
                        if (controller.isUploading) ...[
                          uploadProgressCard(controller),
                          16.heightSpacer,
                        ],
                        if (controller.visibleMedia.isEmpty)
                          emptyState(controller)
                        else
                          mediaGrid(controller),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
          // Pinned, so adding work never means scrolling past a full grid.
          bottomNavigationBar: uploadButtons(controller),
        );
      },
    );
  }

  Widget hero(VendorPortfolioController controller) {
    final serviceName = controller.selectedSubcategoryName ?? '';

    return VendorHero(
      bottomPadding: 56.getSize,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          VendorIconButton(icon: Icons.chevron_left, onTap: Get.back),
          18.heightSpacer,
          VendorHeroTitle(
            eyebrow: controller.selectedCategoryName ?? tr(StringRes.vendorPortfolioTitle),
            title: serviceName,
            subtitle: tr(StringRes.vendorPortfolioGalleryDesc, args: [serviceName]),
          ),
        ],
      ),
    );
  }

  /// Live progress for the upload in flight. Above the grid rather than a
  /// blocking overlay: a video that takes half a minute to send should not
  /// lock the screen. Determinate while bytes go out, indeterminate once
  /// they have all been sent — see the controller.
  Widget uploadProgressCard(VendorPortfolioController controller) {
    final progress = controller.uploadProgress;
    final isVideo = controller.uploadingType == 'video';

    return Container(
      padding: EdgeInsets.all(14.getSize),
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
              VendorIconTile(icon: isVideo ? Icons.videocam_outlined : Icons.photo_outlined, size: 36),
              12.widthSpacer,
              Expanded(
                child: BaseTextDMSans(
                  text: tr(isVideo ? StringRes.vendorUploadingVideo : StringRes.vendorUploadingPhoto),
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: ServiceTokens.text,
                  textAlign: TextAlign.start,
                ),
              ),
              8.widthSpacer,
              BaseTextDMSans(
                text: progress == null
                    ? tr(StringRes.vendorUploadFinishing)
                    : '${(progress * 100).round()}%',
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.accentBright,
              ),
            ],
          ),
          12.heightSpacer,
          ClipRRect(
            borderRadius: BorderRadius.circular(6.getSize),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 6.getSize,
              backgroundColor: ServiceTokens.stroke2,
              valueColor: const AlwaysStoppedAnimation<Color>(ServiceTokens.accentBright),
            ),
          ),
        ],
      ),
    );
  }

  Widget emptyState(VendorPortfolioController controller) {
    return DottedBorderBox(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const VendorIconTile(icon: Icons.photo_outlined, size: 62),
          16.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.vendorNoPortfolioForService),
            fontSize: 16,
            fontWeight: FontWeight.w800,
            color: ServiceTokens.text,
            textAlign: TextAlign.center,
            maxLines: 2,
          ),
          8.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.vendorNoPortfolioDesc),
            fontSize: 14,
            color: ServiceTokens.muted,
            textAlign: TextAlign.center,
            maxLines: 3,
          ),
        ],
      ),
    );
  }

  Widget mediaGrid(VendorPortfolioController controller) {
    final items = controller.visibleMedia;

    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      padding: EdgeInsets.zero,
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 3,
        mainAxisSpacing: 10.getSize,
        crossAxisSpacing: 10.getSize,
      ),
      itemCount: items.length,
      itemBuilder: (_, index) {
        final item = items[index];

        return PortfolioMediaTile(
          type: item.type,
          url: item.url,
          onTap: () => controller.openPreview(item),
          // Uploads are live straight away, so the only status worth a
          // badge is the exception: an admin took this one down.
          badge: item.moderationStatus == 'rejected' ? removedBadge() : null,
        );
      },
    );
  }

  Widget removedBadge() {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 8.getSize, vertical: 4.getSize),
      decoration: BoxDecoration(
        color: ColorRes.errorColor.withValues(alpha: 0.9),
        borderRadius: BorderRadius.circular(7.getSize),
      ),
      child: BaseTextDMSans(
        text: tr(StringRes.moderationRejected),
        fontSize: 10,
        fontWeight: FontWeight.w600,
        color: ColorRes.whiteColor,
        textAlign: TextAlign.center,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
    );
  }

  Widget uploadButtons(VendorPortfolioController controller) {
    return Container(
      padding: EdgeInsets.fromLTRB(16.getSize, 12.getSize, 16.getSize, 12.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        border: Border(top: BorderSide(color: ServiceTokens.stroke)),
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            Expanded(
              child: VendorPrimaryButton(
                label: tr(StringRes.vendorAddPhoto),
                icon: Icons.photo_camera_outlined,
                enabled: !controller.isUploading,
                onTap: controller.pickAndUploadPhoto,
              ),
            ),
            12.widthSpacer,
            Expanded(
              child: VendorSecondaryButton(
                label: tr(StringRes.vendorAddVideo),
                icon: Icons.videocam_outlined,
                onTap: controller.isUploading ? () {} : controller.pickAndUploadVideo,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
