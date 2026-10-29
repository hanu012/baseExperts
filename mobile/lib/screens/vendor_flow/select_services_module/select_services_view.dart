import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'select_services_controller.dart';
import 'select_zones_view.dart';

/// Add Vendor, step 2 of 3 — categories and subcategories (SPEC 2.2).
///
/// Zones moved to their own step ([SelectZonesView]). One controller still
/// owns the whole selection and does the single subscribe call at the end;
/// this screen only hands off, it never submits. That keeps the server
/// contract untouched — categories, subcategories and zones still arrive
/// together in one request — while giving each half a screen it can fill.
class SelectServicesView extends StatelessWidget {
  final int vendorId;
  final PlanModel plan;
  final String businessName;
  final String loginEmail;
  final bool isRenewal;

  const SelectServicesView({
    super.key,
    required this.vendorId,
    required this.plan,
    required this.businessName,
    required this.loginEmail,
    this.isRenewal = false,
  });

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SelectServicesController>(
      init: SelectServicesController(
        vendorId: vendorId,
        plan: plan,
        businessName: businessName,
        loginEmail: loginEmail,
        isRenewal: isRenewal,
      ),
      // No explicit dispose: the zones step reads this same controller off
      // GetX while this screen stays alive beneath it. As the creator, this
      // GetBuilder auto-removes the controller once this screen leaves the
      // stack, which is when the flow is actually over.
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

  List<Widget> bodyChildren(SelectServicesController controller) {
    if (controller.isLoading && controller.categories.isEmpty) {
      return [
        ServicesSearchBar(
          controller: controller.searchController,
          hintText: tr(StringRes.searchSubcategoriesHint),
          onChanged: controller.onSearchChanged,
          filterActive: false,
          onFilterTap: () {},
        ),
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
      ServicesSearchBar(
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
      ),
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

  Widget chipsRow(SelectServicesController controller) {
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

  Widget categoryCard(SelectServicesController controller, CategoryModel category) {
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
          for (final sub in visibleSubs)
            ServicesRow(
              label: sub.name ?? '',
              selected: controller.isSubcategorySelected(sub.id ?? -1),
              enabled: controller.isSubcategorySelected(sub.id ?? -1) ||
                  !controller.subcategoryQuotaReached,
              onTap: () => controller.toggleSubcategory(sub, category),
            ),
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

  void onContinueTap(SelectServicesController controller) {
    if (!controller.validateServices()) {
      return;
    }

    Get.to(() => const SelectZonesView());
  }
}
