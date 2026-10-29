import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'customer_subcategories_controller.dart';

/// Subcategory list for a tapped category (SPEC section 4 items 3-4, task
/// 5.1) — a search field, the Installation/Repair/Maintenance filter
/// chips, and one row per service with its live vendor count.
class CustomerSubcategoriesView extends StatelessWidget {
  final CategoryModel category;
  final int? zoneId;
  final double? latitude;
  final double? longitude;
  final String? pincode;

  const CustomerSubcategoriesView({
    super.key,
    required this.category,
    required this.zoneId,
    required this.latitude,
    required this.longitude,
    required this.pincode,
  });

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerSubcategoriesController>(
      init: CustomerSubcategoriesController(
        category: category,
        zoneId: zoneId,
        latitude: latitude,
        longitude: longitude,
        pincode: pincode,
      ),
      dispose: (_) => Get.delete<CustomerSubcategoriesController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: Column(
            children: [
              hero(controller),
              filterChips(controller),
              Expanded(child: body(controller)),
            ],
          ),
        );
      },
    );
  }

  Widget hero(CustomerSubcategoriesController controller) {
    return CustomerHero(
      bottomPadding: 20.getSize,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              CustomerIconButton(
                icon: Icons.chevron_left,
                onTap: Get.back,
                tooltip: tr(StringRes.backToLogin),
              ),
              Expanded(
                child: BaseTextDMSans(
                  text: category.name ?? '',
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: Colors.white,
                  textAlign: TextAlign.center,
                ),
              ),
              // Balances the back chip so the title sits truly centred.
              SizedBox(width: 42.getSize),
            ],
          ),
          18.heightSpacer,
          Row(
            children: [
              Container(
                height: 54.getSize,
                width: 54.getSize,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.18),
                  borderRadius: BorderRadius.circular(16.getSize),
                ),
                child: category.iconUrl != null
                    ? Padding(
                        padding: EdgeInsets.all(10.getSize),
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(8.getSize),
                          child: Image.network(
                            category.iconUrl!,
                            fit: BoxFit.contain,
                            errorBuilder: (_, _, _) => Icon(
                              customerServiceIcon(category.name),
                              color: Colors.white,
                              size: 26.getSize,
                            ),
                          ),
                        ),
                      )
                    : Icon(
                        customerServiceIcon(category.name),
                        color: Colors.white,
                        size: 26.getSize,
                      ),
              ),
              14.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: category.name ?? '',
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    4.heightSpacer,
                    BaseTextDMSans(
                      text: tr(StringRes.chooseAServiceDesc),
                      fontSize: 12,
                      color: Colors.white.withValues(alpha: 0.78),
                    ),
                  ],
                ),
              ),
            ],
          ),
          18.heightSpacer,
          searchField(controller),
        ],
      ),
    );
  }

  Widget searchField(CustomerSubcategoriesController controller) {
    return Container(
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(15.getSize),
        boxShadow: ServiceTokens.searchShadow,
      ),
      padding: EdgeInsets.symmetric(horizontal: 14.getSize),
      child: Row(
        children: [
          Icon(Icons.search, color: ServiceTokens.muted2, size: 20.getSize),
          10.widthSpacer,
          Expanded(
            child: TextField(
              controller: controller.searchController,
              onChanged: controller.onQueryChanged,
              style: TextStyle(
                color: ServiceTokens.text,
                fontSize: 14.getFontSize,
                fontFamily: FontFamily.poppins,
                fontWeight: FontWeight.w500,
              ),
              cursorColor: ServiceTokens.accent,
              decoration: InputDecoration(
                isDense: true,
                border: InputBorder.none,
                contentPadding: EdgeInsets.symmetric(vertical: 15.getSize),
                hintText: tr(StringRes.searchInCategoryHint, args: [category.name ?? '']),
                hintStyle: TextStyle(
                  color: ServiceTokens.muted2,
                  fontSize: 14.getFontSize,
                  fontFamily: FontFamily.poppins,
                ),
              ),
            ),
          ),
          if (controller.query.isNotEmpty)
            GestureDetector(
              onTap: () {
                controller.searchController.clear();
                controller.onQueryChanged('');
              },
              behavior: HitTestBehavior.opaque,
              child: Padding(
                padding: EdgeInsets.only(left: 6.getSize),
                child: Icon(Icons.close, color: ServiceTokens.muted, size: 19.getSize),
              ),
            ),
        ],
      ),
    );
  }

  Widget filterChips(CustomerSubcategoriesController controller) {
    final chips = <(ServiceTypeFilter, String, bool)>[
      (ServiceTypeFilter.all, tr(StringRes.filterAll), true),
      (
        ServiceTypeFilter.installation,
        tr(StringRes.filterInstallation),
        controller.hasAnySubcategoryOfType('installation'),
      ),
      (
        ServiceTypeFilter.repair,
        tr(StringRes.filterRepair),
        controller.hasAnySubcategoryOfType('repair'),
      ),
      (
        ServiceTypeFilter.maintenance,
        tr(StringRes.filterMaintenance),
        controller.hasAnySubcategoryOfType('maintenance'),
      ),
    ];

    final visible = chips.where((c) => c.$3).toList();

    // Nothing to filter by beyond "All" — the row would just be one chip
    // doing nothing, so it does not render at all.
    if (visible.length <= 1) {
      return const SizedBox.shrink();
    }

    return Padding(
      padding: EdgeInsets.fromLTRB(16.getSize, 14.getSize, 16.getSize, 8.getSize),
      child: SizedBox(
        height: 38.getSize,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: EdgeInsets.zero,
          itemCount: visible.length,
          separatorBuilder: (_, _) => 8.widthSpacer,
          itemBuilder: (_, index) {
            final (filter, label, _) = visible[index];
            final selected = controller.typeFilter == filter;

            return Material(
              color: selected ? ServiceTokens.accent : ServiceTokens.card,
              borderRadius: BorderRadius.circular(20.getSize),
              child: InkWell(
                onTap: () => controller.selectTypeFilter(filter),
                borderRadius: BorderRadius.circular(20.getSize),
                child: Container(
                  alignment: Alignment.center,
                  padding: EdgeInsets.symmetric(horizontal: 16.getSize),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(20.getSize),
                    border: Border.all(
                      color: selected ? ServiceTokens.accent : ServiceTokens.stroke,
                    ),
                  ),
                  child: BaseTextDMSans(
                    text: label,
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: selected ? Colors.white : ServiceTokens.text,
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }

  Widget body(CustomerSubcategoriesController controller) {
    final subcategories = controller.visibleSubcategories;

    if (subcategories.isEmpty) {
      return emptyState();
    }

    return ListView(
      padding: EdgeInsets.fromLTRB(16.getSize, 4.getSize, 16.getSize, 24.getSize),
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            BaseTextDMSans(
              text: controller.typeFilter == ServiceTypeFilter.all
                  ? tr(StringRes.allServicesHeading)
                  : _typeLabel(controller.typeFilter),
              fontSize: 16,
              fontWeight: FontWeight.w800,
              color: ServiceTokens.text,
            ),
            BaseTextDMSans(
              text: tr(StringRes.serviceCountLabel, args: ['${subcategories.length}']),
              fontSize: 12,
              color: ServiceTokens.muted,
            ),
          ],
        ),
        14.heightSpacer,
        for (final subcategory in subcategories)
          Padding(
            padding: EdgeInsets.only(bottom: 10.getSize),
            child: subcategoryRow(controller, subcategory),
          ),
      ],
    );
  }

  Widget subcategoryRow(
    CustomerSubcategoriesController controller,
    SubcategoryModel subcategory,
  ) {
    final count = controller.vendorCountFor(subcategory);

    return Material(
      color: ServiceTokens.card,
      borderRadius: BorderRadius.circular(16.getSize),
      child: InkWell(
        onTap: () => controller.selectSubcategory(subcategory),
        borderRadius: BorderRadius.circular(16.getSize),
        child: Container(
          padding: EdgeInsets.all(14.getSize),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16.getSize),
            border: Border.all(color: ServiceTokens.stroke),
          ),
          child: Row(
            children: [
              CustomerIconTile(icon: customerServiceIcon(subcategory.name), size: 44),
              12.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: subcategory.name ?? '',
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: ServiceTokens.text,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    4.heightSpacer,
                    subtitleLine(controller, subcategory, count),
                  ],
                ),
              ),
              if (subcategory.isPopular) ...[
                8.widthSpacer,
                popularBadge(),
              ],
              8.widthSpacer,
              Icon(Icons.chevron_right, color: ServiceTokens.muted2, size: 20.getSize),
            ],
          ),
        ),
      ),
    );
  }

  /// "Installation · 5 vendors" — the type when known, the vendor count
  /// always (once it has loaded). A loading count shows nothing rather
  /// than a confident wrong number.
  Widget subtitleLine(
    CustomerSubcategoriesController controller,
    SubcategoryModel subcategory,
    int count,
  ) {
    final parts = <String>[];

    if (subcategory.serviceType != null) {
      parts.add(_typeLabel(ServiceTypeFilter.values.byName(subcategory.serviceType!)));
    }

    if (!controller.isLoadingCounts) {
      parts.add(
        count == 1
            ? '1 ${tr(StringRes.vendorCountSuffix)}'
            : '$count ${tr(StringRes.vendorsCountSuffix)}',
      );
    }

    return BaseTextDMSans(
      text: parts.join(' · '),
      fontSize: 12,
      color: ServiceTokens.muted,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
    );
  }

  Widget popularBadge() {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 9.getSize, vertical: 4.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.accent,
        borderRadius: BorderRadius.circular(10.getSize),
      ),
      child: BaseTextDMSans(
        text: tr(StringRes.popularBadge),
        fontSize: 10,
        fontWeight: FontWeight.w600,
        color: Colors.white,
      ),
    );
  }

  Widget emptyState() {
    return Padding(
      padding: EdgeInsets.fromLTRB(28.getSize, 48.getSize, 28.getSize, 0),
      child: Column(
        children: [
          const CustomerIconTile(icon: Icons.search_off, size: 56),
          14.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.noServicesMatchFilter),
            fontSize: 16,
            fontWeight: FontWeight.w800,
            color: ServiceTokens.text,
            textAlign: TextAlign.center,
          ),
          8.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.noServicesMatchFilterDesc),
            fontSize: 14,
            color: ServiceTokens.muted,
            textAlign: TextAlign.center,
            maxLines: 3,
          ),
        ],
      ),
    );
  }

  String _typeLabel(ServiceTypeFilter filter) {
    return switch (filter) {
      ServiceTypeFilter.all => tr(StringRes.filterAll),
      ServiceTypeFilter.installation => tr(StringRes.filterInstallation),
      ServiceTypeFilter.repair => tr(StringRes.filterRepair),
      ServiceTypeFilter.maintenance => tr(StringRes.filterMaintenance),
    };
  }
}
