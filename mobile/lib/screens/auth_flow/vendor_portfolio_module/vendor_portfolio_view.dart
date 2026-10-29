import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_portfolio_controller.dart';

/// Vendor dashboard, Portfolio tab (SPEC section 3 item 5, task 4.5) — the
/// third destination on the bottom bar.
///
/// Step 1 of 3: the vendor's main categories. Tapping one opens its
/// services, and a service opens the gallery where photos and videos are
/// added — so every upload is filed under exactly the service it shows,
/// which is how customers see it too.
class VendorPortfolioView extends StatelessWidget {
  const VendorPortfolioView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorPortfolioController>(
      init: VendorPortfolioController(),
      dispose: (_) => Get.delete<VendorPortfolioController>(),
      builder: (controller) {
        if (controller.isLoading && controller.media.isEmpty && controller.subcategories.isEmpty) {
          return const Center(
            child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
          );
        }

        return RefreshIndicator(
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
                  padding: EdgeInsets.fromLTRB(16.getSize, 0, 16.getSize, 0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      quotaRow(controller),
                      12.heightSpacer,
                      liveNote(),
                      20.heightSpacer,
                      if (controller.portfolioCategories.isEmpty)
                        noServices()
                      else
                        categoryList(controller),
                      24.heightSpacer,
                    ],
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget hero(VendorPortfolioController controller) {
    return VendorHero(
      bottomPadding: 56.getSize,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          VendorBrandRow(
            initials: controller.initials,
            businessName: controller.businessName,
            photoUrl: controller.avatarUrl,
          ),
          18.heightSpacer,
          VendorHeroTitle(
            title: tr(StringRes.vendorPortfolioTitle),
            subtitle: tr(StringRes.vendorPortfolioCategoriesDesc),
          ),
        ],
      ),
    );
  }

  /// Plan-wide photo and video usage. Shared with the gallery screen,
  /// where the vendor decides whether there is room for one more.
  static Widget quotaRow(VendorPortfolioController controller) {
    return Row(
      children: [
        Expanded(
          child: VendorStatTile(
            icon: Icons.photo_outlined,
            label: tr(StringRes.photosLabel),
            used: controller.photosQuota?.used ?? 0,
            max: controller.photosQuota?.max ?? 0,
          ),
        ),
        12.widthSpacer,
        Expanded(
          child: VendorStatTile(
            icon: Icons.videocam_outlined,
            label: tr(StringRes.videosLabel),
            used: controller.videosQuota?.used ?? 0,
            max: controller.videosQuota?.max ?? 0,
          ),
        ),
      ],
    );
  }

  /// Uploads skip review now — said once, here, so the vendor is not left
  /// wondering when their work will show up.
  static Widget liveNote() {
    return Row(
      children: [
        Icon(Icons.check_circle_outline, size: 16.getSize, color: ServiceTokens.green),
        8.widthSpacer,
        Expanded(
          child: BaseTextDMSans(
            text: tr(StringRes.vendorPortfolioLiveNote),
            fontSize: 12,
            color: ServiceTokens.muted,
            textAlign: TextAlign.start,
            maxLines: 2,
          ),
        ),
      ],
    );
  }

  Widget categoryList(VendorPortfolioController controller) {
    final categories = controller.portfolioCategories;

    return VendorSettingsGroup(
      title: tr(StringRes.vendorPortfolioCategoriesTitle),
      rows: [
        for (var index = 0; index < categories.length; index++)
          VendorSettingsRow(
            first: index == 0,
            icon: categories[index].id == VendorPortfolioController.otherCategoryId
                ? Icons.more_horiz
                : Icons.category_outlined,
            title: categories[index].name ?? '',
            subtitle: tr(
              StringRes.vendorPortfolioCategoryMeta,
              args: [
                '${controller.subcategoriesOf(categories[index].id).length}',
                '${controller.categoryPhotoCount(categories[index].id)}',
                '${controller.categoryVideoCount(categories[index].id)}',
              ],
            ),
            trailing: const VendorRowChevron(),
            onTap: () => controller.openCategory(categories[index]),
          ),
      ],
    );
  }

  Widget noServices() {
    return DottedBorderBox(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const VendorIconTile(icon: Icons.design_services_outlined, size: 62),
          16.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.vendorPortfolioNoServices),
            fontSize: 16,
            fontWeight: FontWeight.w800,
            color: ServiceTokens.text,
            textAlign: TextAlign.center,
            maxLines: 2,
          ),
          8.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.vendorPortfolioNoServicesDesc),
            fontSize: 14,
            color: ServiceTokens.muted,
            textAlign: TextAlign.center,
            maxLines: 3,
          ),
        ],
      ),
    );
  }
}
