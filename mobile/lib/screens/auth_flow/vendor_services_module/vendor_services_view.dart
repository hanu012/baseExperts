import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_dashboard_module/vendor_dashboard_controller.dart';
import '../vendor_select_services_module/vendor_select_services_view.dart';

/// The vendor's own "what I sell and where" screen (SPEC section 3.2) —
/// the second destination on the bottom bar.
///
/// No controller of its own, which is the one place this module departs
/// from the two-file rule, and deliberately: this screen and Overview are
/// two readings of the same GET /api/vendors/me response (quota bars there,
/// the item list here). Giving it its own controller would mean two
/// independent fetches of one payload, free to disagree with each other
/// mid-session — so it binds to [VendorDashboardController] instead, the
/// same way the salesman profile's detail screen binds to the profile
/// controller behind it.
class VendorServicesView extends StatelessWidget {
  const VendorServicesView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorDashboardController>(
      // Find, not init: the shell owns this controller's lifetime.
      init: Get.find<VendorDashboardController>(),
      builder: (controller) {
        final subscription = controller.vendorMe?.activeSubscription;

        if (subscription == null) {
          return const SizedBox.shrink();
        }

        return RefreshIndicator(
          onRefresh: controller.fetchVendorMeAPI,
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
                    children: [
                      categoriesCard(subscription),
                      14.heightSpacer,
                      subcategoriesCard(subscription),
                      14.heightSpacer,
                      zonesCard(subscription),
                      20.heightSpacer,
                      VendorPrimaryButton(
                        label: tr(StringRes.editServices),
                        icon: Icons.add,
                        onTap: () => addMoreServices(controller),
                      ),
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

  Widget hero(VendorDashboardController controller) {
    return VendorHero(
      bottomPadding: 56.getSize,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          VendorBrandRow(
            initials: controller.initials,
            businessName: controller.vendorMe?.businessName ?? '',
            photoUrl: controller.vendorMe?.avatarUrl,
          ),
          18.heightSpacer,
          VendorHeroTitle(
            title: tr(StringRes.vendorServicesTitle),
            subtitle: tr(StringRes.vendorServicesDesc),
          ),
        ],
      ),
    );
  }

  Widget categoriesCard(ActiveSubscriptionModel subscription) {
    return sectionCard(
      icon: Icons.folder_outlined,
      title: tr(StringRes.categoriesSection),
      quota: subscription.categories,
      child: chipWrap(
        subscription.selectedCategories.map((c) => c.name ?? '').toList(),
      ),
    );
  }

  /// Subcategories, grouped under the category they belong to — the server
  /// sends `category_id` on each one for exactly this.
  Widget subcategoriesCard(ActiveSubscriptionModel subscription) {
    final byCategory = <int?, List<String>>{};

    for (final sub in subscription.selectedSubcategories) {
      byCategory.putIfAbsent(sub.categoryId, () => []).add(sub.name ?? '');
    }

    final categoryNames = {
      for (final c in subscription.selectedCategories) c.id: c.name ?? '',
    };

    return sectionCard(
      icon: Icons.list_alt_outlined,
      title: tr(StringRes.subcategoriesCounted),
      quota: subscription.subcategories,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final entry in byCategory.entries) ...[
            // A heading only where the parent is known — a subcategory
            // whose category was dropped from the selection still has to
            // be listed, just without a group label it cannot resolve.
            if (categoryNames[entry.key] != null) ...[
              BaseTextDMSans(
                text: categoryNames[entry.key]!.toUpperCase(),
                fontSize: 12,
                fontWeight: FontWeight.w600,
                letterSpacing: 0.9,
                color: ServiceTokens.muted2,
                textAlign: TextAlign.start,
              ),
              9.heightSpacer,
            ],
            chipWrap(entry.value),
            if (entry.key != byCategory.keys.last) 14.heightSpacer,
          ],
        ],
      ),
    );
  }

  Widget zonesCard(ActiveSubscriptionModel subscription) {
    return sectionCard(
      icon: Icons.location_on_outlined,
      title: tr(StringRes.zonesSection),
      quota: subscription.zones,
      child: chipWrap(
        subscription.selectedZones.map((z) => z.name ?? '').toList(),
        icon: Icons.location_on_outlined,
      ),
    );
  }

  /// Shared shell: icon tile, title, "2 of 5 used", the remaining-count
  /// badge, then whatever the section lists.
  Widget sectionCard({
    required IconData icon,
    required String title,
    required QuotaResourceModel? quota,
    required Widget child,
  }) {
    final used = quota?.used ?? 0;
    final max = quota?.max ?? 0;
    final left = (max - used).clamp(0, max);

    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              VendorIconTile(icon: icon, size: 38),
              12.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: title,
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                    ),
                    3.heightSpacer,
                    BaseTextDMSans(
                      text: tr(StringRes.vendorUsedOf, args: ['$used', '$max']),
                      fontSize: 12,
                      color: ServiceTokens.muted,
                      textAlign: TextAlign.start,
                    ),
                  ],
                ),
              ),
              8.widthSpacer,
              // Muted once nothing is left: a green "0 left" reads as a
              // good thing when it is the opposite.
              VendorBadge(
                label: tr(StringRes.vendorLeftCount, args: ['$left']),
                muted: left == 0,
              ),
            ],
          ),
          14.heightSpacer,
          child,
        ],
      ),
    );
  }

  Widget chipWrap(List<String> labels, {IconData? icon}) {
    if (labels.isEmpty) {
      return BaseTextDMSans(
        text: StringRes.notSetLabel,
        fontSize: 12,
        color: ServiceTokens.muted2,
        textAlign: TextAlign.start,
      ).tr();
    }

    return Wrap(
      spacing: 9.getSize,
      runSpacing: 9.getSize,
      children: [
        for (final label in labels) VendorChip(label: label, icon: icon),
      ],
    );
  }

  Future<void> addMoreServices(VendorDashboardController controller) async {
    final subscription = controller.vendorMe!.activeSubscription!;

    await Get.to(
      () => VendorSelectServicesView(
        // Unused by addServicesAPI (task 4.4 resolves the vendor from the
        // auth token server-side) — kept only because the picker screen is
        // shared with the initial-subscribe flow, which does need it.
        vendorId: controller.vendorMe?.id ?? 0,
        plan: PlanModel(
          // Shown in the footer hint ("… left in the Silver plan").
          name: subscription.planName,
          maxCategories: subscription.categories?.max,
          maxSubcategories: subscription.subcategories?.max,
          maxZones: subscription.zones?.max,
        ),
        isAddingMore: true,
        existingCategoryIds: subscription.selectedCategories
            .map((e) => e.id)
            .whereType<int>()
            .toSet(),
        existingSubcategoryIds: subscription.selectedSubcategories
            .map((e) => e.id)
            .whereType<int>()
            .toSet(),
        existingZoneIds:
            subscription.selectedZones.map((e) => e.id).whereType<int>().toSet(),
      ),
    );

    controller.fetchVendorMeAPI();
  }
}
