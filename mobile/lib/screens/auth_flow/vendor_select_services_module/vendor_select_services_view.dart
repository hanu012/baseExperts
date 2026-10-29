import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_select_services_controller.dart';
import 'vendor_select_zones_view.dart';

/// Vendor self-service, step 2 of 3 — categories and subcategories
/// (SPEC section 3.2). Also serves task 4.4's "add more within remaining
/// quota" when constructed with `isAddingMore: true` plus the vendor's
/// existing selections — see the controller's docblock.
///
/// Zones moved to their own step ([VendorSelectZonesView]); one controller
/// still owns the whole selection and makes the single call at the end.
/// In add-more mode earlier picks start ticked and can be unticked to free
/// room for something else; the controller confirms before saving a
/// removal.
class VendorSelectServicesView extends StatelessWidget {
  final int vendorId;
  final PlanModel plan;
  final bool isAddingMore;

  /// Renew instead of a first subscription — see VendorSelectPlanController.
  final bool isRenewal;
  final Set<int> existingCategoryIds;
  final Set<int> existingSubcategoryIds;
  final Set<int> existingZoneIds;

  const VendorSelectServicesView({
    super.key,
    required this.vendorId,
    required this.plan,
    this.isAddingMore = false,
    this.isRenewal = false,
    this.existingCategoryIds = const {},
    this.existingSubcategoryIds = const {},
    this.existingZoneIds = const {},
  });

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorSelectServicesController>(
      init: VendorSelectServicesController(
        vendorId: vendorId,
        plan: plan,
        isAddingMore: isAddingMore,
        isRenewal: isRenewal,
        existingCategoryIds: existingCategoryIds,
        existingSubcategoryIds: existingSubcategoryIds,
        existingZoneIds: existingZoneIds,
      ),
      // No explicit dispose: the zones step reads this same controller
      // while this screen stays beneath it. As the creator, this
      // GetBuilder auto-removes it once this screen leaves the stack.
      builder: (controller) {
        final picked = controller.selectedSubcategoryIds.length;

        return ServicesPage(
          header: const ServicesHeader(
            title: StringRes.selectServicesTitle,
            subtitle: StringRes.selectServicesDesc,
            step: 2,
            totalSteps: 3,
          ),
          footer: ServicesFooter(
            hint: controller.subcategoriesRemaining > 0
                ? '${controller.subcategoriesRemaining} '
                    '${tr(StringRes.subcategoriesLeftInPlan)} '
                    '${plan.name ?? ''} ${tr(StringRes.planSuffix)}'
                : '',
            label: StringRes.continueLabel,
            countLabel: picked > 0 ? '$picked ${tr(StringRes.selectedSuffix)}' : null,
            onPressed: () => onContinueTap(controller),
          ),
          children: bodyChildren(controller),
        );
      },
    );
  }

  List<Widget> bodyChildren(VendorSelectServicesController controller) {
    final searchBar = ServicesSearchBar(
      controller: controller.searchController,
      hintText: tr(StringRes.searchSubcategoriesHint),
      onChanged: controller.onSearchChanged,
      filterActive: controller.searchQuery.isNotEmpty ||
          controller.activeCategoryFilterId != null,
      onFilterTap: () {
        controller.searchController.clear();
        controller.onSearchChanged('');
        controller.selectCategoryFilter(null);
      },
    );

    if (controller.isLoading && controller.categories.isEmpty) {
      return [
        searchBar,
        Padding(
          padding: EdgeInsets.symmetric(vertical: 60.getSize),
          child: Center(
            child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
          ),
        ),
      ];
    }

    final filtered = controller.filteredCategories;

    return [
      searchBar,
      14.heightSpacer,
      chipsRow(controller),
      14.heightSpacer,
      ServicesStatRow(
        left: ServicesStatTile(
          value: '${controller.selectedCategoryIds.length}',
          suffix: '/ ${controller.maxCategories}',
          label: tr(StringRes.categoriesSection),
        ),
        right: ServicesStatTile(
          value: '${controller.selectedSubcategoryIds.length}',
          suffix: '/ ${controller.maxSubcategories}',
          label: tr(StringRes.subcategoriesCounted),
        ),
      ),
      16.heightSpacer,
      if (filtered.isEmpty)
        ServicesEmptyState(message: tr(StringRes.noMatchingServices))
      else
        for (final category in filtered) categoryCard(controller, category),
    ];
  }

  Widget chipsRow(VendorSelectServicesController controller) {
    return SizedBox(
      height: 38.getSize,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: [
          ServicesChip(
            label: tr(StringRes.allCategoriesFilter),
            selected: controller.activeCategoryFilterId == null,
            onTap: () => controller.selectCategoryFilter(null),
          ),
          for (final category in controller.categories)
            Padding(
              padding: EdgeInsets.only(left: 9.getSize),
              child: ServicesChip(
                label: category.name ?? '',
                selected: controller.activeCategoryFilterId == category.id,
                onTap: () => controller.selectCategoryFilter(category.id),
              ),
            ),
        ],
      ),
    );
  }

  Widget categoryCard(
    VendorSelectServicesController controller,
    CategoryModel category,
  ) {
    final id = category.id ?? -1;
    final expanded = controller.isCategoryExpanded(id);
    final visibleSubs = controller.visibleSubcategories(category);
    final total = category.subcategories.length;
    final picked = controller.selectedCountIn(category);
    final allPicked = total > 0 && picked == total;

    return ServicesGroupCard(
      header: ServicesGroupHead(
        title: category.name ?? '',
        countLine: picked == 0
            ? tr(StringRes.tapToChoose)
            : '$picked ${tr(StringRes.of)} $total ${tr(StringRes.selectedSuffix)}',
        fallbackIcon: Icons.handyman_outlined,
        iconUrl: category.iconUrl,
        badge: allPicked
            ? ServicesBadge(label: tr(StringRes.allLabel))
            : (picked > 0
                ? ServicesBadge(label: '$picked ${tr(StringRes.pickedSuffix)}')
                : const ServicesBadge(label: '0', muted: true)),
        expanded: expanded,
        onTap: () => controller.toggleCategoryExpanded(id),
      ),
      rows: [
        if (expanded)
          for (final sub in visibleSubs) subcategoryRow(controller, sub, category),
      ],
      footer: expanded && visibleSubs.isNotEmpty
          ? ServicesCardFooter(
              actionLabel: tr(StringRes.selectAllLabel),
              actionEnabled: !allPicked && !controller.subcategoryQuotaReached,
              onAction: () => controller.selectAllIn(category),
              trailing: '$picked ${tr(StringRes.selectedSuffix)}',
            )
          : null,
    );
  }

  Widget subcategoryRow(
    VendorSelectServicesController controller,
    SubcategoryModel subcategory,
    CategoryModel parent,
  ) {
    final id = subcategory.id ?? -1;
    final selected = controller.isSubcategorySelected(id);

    return ServicesRow(
      label: subcategory.name ?? '',
      selected: selected,
      // A ticked row can always be unticked, earlier picks included; an
      // unticked one only while the plan still has room.
      enabled: selected || !controller.subcategoryQuotaReached,
      onTap: () => controller.toggleSubcategory(subcategory, parent),
    );
  }

  void onContinueTap(VendorSelectServicesController controller) {
    if (!controller.validateServices()) {
      return;
    }

    Get.to(() => const VendorSelectZonesView());
  }
}
