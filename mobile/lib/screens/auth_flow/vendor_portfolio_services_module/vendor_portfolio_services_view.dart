import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_portfolio_module/vendor_portfolio_controller.dart';

/// Portfolio, step 2 of 3: the services under one category, each with a
/// thumbnail of its newest photo and how much work is filed there.
/// Tapping one opens its gallery.
///
/// No controller of its own: it listens to the Portfolio tab's
/// [VendorPortfolioController], which stays registered underneath (see
/// that class). No `init:` here, so this screen never creates or deletes
/// it — the same pattern the review and report sheets use.
class VendorPortfolioServicesView extends StatelessWidget {
  const VendorPortfolioServicesView({
    super.key,
    required this.categoryId,
    required this.categoryName,
  });

  final int categoryId;
  final String categoryName;

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorPortfolioController>(
      builder: (controller) {
        final services = controller.subcategoriesOf(categoryId);

        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: ListView(
            padding: EdgeInsets.zero,
            children: [
              hero(),
              Transform.translate(
                offset: Offset(0, -34.getSize),
                child: Padding(
                  padding: EdgeInsets.fromLTRB(16.getSize, 0, 16.getSize, 24.getSize),
                  child: Column(
                    children: [
                      for (final service in services) ...[
                        serviceCard(controller, service),
                        12.heightSpacer,
                      ],
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

  Widget hero() {
    return VendorHero(
      bottomPadding: 56.getSize,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          VendorIconButton(icon: Icons.chevron_left, onTap: Get.back),
          18.heightSpacer,
          VendorHeroTitle(
            eyebrow: tr(StringRes.vendorPortfolioTitle),
            title: categoryName,
            subtitle: tr(StringRes.vendorPortfolioServicesDesc),
          ),
        ],
      ),
    );
  }

  Widget serviceCard(VendorPortfolioController controller, SelectedServiceItemModel service) {
    final cover = controller.coverFor(service.id);

    return Material(
      color: ServiceTokens.card,
      borderRadius: BorderRadius.circular(18.getSize),
      child: InkWell(
        onTap: () => controller.openService(service),
        borderRadius: BorderRadius.circular(18.getSize),
        child: Container(
          padding: EdgeInsets.all(12.getSize),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(18.getSize),
            border: Border.all(color: ServiceTokens.stroke),
          ),
          child: Row(
            children: [
              // The newest photo, so the vendor recognises the service by
              // its work; the icon tile until there is any.
              SizedBox(
                height: 56.getSize,
                width: 56.getSize,
                child: cover == null
                    ? const VendorIconTile(icon: Icons.add_photo_alternate_outlined, size: 56)
                    : PortfolioMediaTile(type: cover.type, url: cover.url, radius: 14),
              ),
              14.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    BaseTextDMSans(
                      text: service.name ?? '',
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    4.heightSpacer,
                    BaseTextDMSans(
                      text: tr(
                        StringRes.vendorPortfolioServiceMeta,
                        args: [
                          '${controller.photoCount(service.id)}',
                          '${controller.videoCount(service.id)}',
                        ],
                      ),
                      fontSize: 12,
                      color: ServiceTokens.muted,
                      textAlign: TextAlign.start,
                    ),
                  ],
                ),
              ),
              const VendorRowChevron(),
            ],
          ),
        ),
      ),
    );
  }
}
