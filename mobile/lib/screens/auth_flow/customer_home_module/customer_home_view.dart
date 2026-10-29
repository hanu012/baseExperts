import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../customer_favorites_module/customer_favorites_view.dart';
import '../customer_profile_module/customer_profile_view.dart';
import '../customer_service_search_module/customer_service_search_view.dart';
import '../customer_subcategories_module/customer_subcategories_view.dart';
import '../vendor_detail_module/vendor_detail_view.dart';
import '../vendor_search_module/vendor_search_view.dart';
import 'customer_home_controller.dart';

/// Customer home (SPEC sections 4.2-4.4) — and the shell that hosts the
/// bottom bar.
///
/// The three destinations live in an [IndexedStack], so switching tabs
/// keeps each one's scroll position and does not refetch the tab being
/// left. Favourites and Profile are reached through the bar rather than
/// through app-bar icons, which is where the design puts them.
class CustomerHomeView extends StatelessWidget {
  const CustomerHomeView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerHomeController>(
      init: CustomerHomeController(),
      dispose: (_) => Get.delete<CustomerHomeController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: IndexedStack(
            index: controller.tabIndex,
            children: [
              homeTab(controller),
              const CustomerFavoritesView(embedded: true),
              const CustomerProfileView(),
            ],
          ),
          bottomNavigationBar: CustomerBottomNav(
            currentIndex: controller.tabIndex,
            onTap: controller.changeTab,
            items: [
              CustomerNavItem(
                icon: Icons.home_outlined,
                label: tr(StringRes.homeTab),
              ),
              CustomerNavItem(
                icon: Icons.favorite_border,
                label: tr(StringRes.favoritesTab),
              ),
              CustomerNavItem(
                icon: Icons.person_outline,
                label: tr(StringRes.profileTab),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget homeTab(CustomerHomeController controller) {
    return RefreshIndicator(
      onRefresh: controller.refreshAll,
      color: ServiceTokens.accentBright,
      backgroundColor: ServiceTokens.card,
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          hero(controller),
          Transform.translate(
            // Lifts the body so the search bar straddles the hero edge.
            offset: Offset(0, -26.getSize),
            child: Padding(
              padding: EdgeInsets.fromLTRB(16.getSize, 0, 16.getSize, 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Clears the search bar, which is translated 24 down
                  // out of the hero and so overhangs this column — a
                  // spacer the size of that overhang would leave none.
                  46.heightSpacer,
                  categoriesSection(controller),
                  24.heightSpacer,
                  popularSection(controller),
                  24.heightSpacer,
                  nearbySection(controller),
                  24.heightSpacer,
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget hero(CustomerHomeController controller) {
    return CustomerHero(
      // Leaves room for the search bar to overlap the bottom edge.
      bottomPadding: 46.getSize,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // IntrinsicHeight so the favourites button matches the pill's
          // height, which is set by the pill's own content — pinning a
          // number here would mean two places to change it.
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(
                  child: CustomerLocationPill(
                    label: tr(StringRes.yourLocationLabel),
                    place: controller.locationLabel ??
                        tr(StringRes.setYourLocation),
                    onTap: controller.openLocationPicker,
                  ),
                ),
                10.widthSpacer,
                CustomerIconButton(
                  icon: Icons.favorite_border,
                  onTap: () => controller.changeTab(1),
                  tooltip: tr(StringRes.favoritesTab),
                  fillHeight: true,
                ),
              ],
            ),
          ),
          20.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.customerGreeting),
            fontSize: 18,
            fontWeight: FontWeight.w600,
            color: Colors.white,
            textAlign: TextAlign.start,
            maxLines: 2,
          ),
          18.heightSpacer,
          // Sits inside the hero's padding but visually overlaps its
          // bottom edge, courtesy of the extra bottomPadding above.
          Transform.translate(
            offset: Offset(0, 24.getSize),
            child: CustomerSearchBar(
              hint: tr(StringRes.customerSearchHint),
              onTap: () => openServiceSearch(controller),
            ),
          ),
        ],
      ),
    );
  }

  // ── Categories ──────────────────────────────────────────────────────────

  Widget categoriesSection(CustomerHomeController controller) {
    if (controller.isLoadingCategories && controller.categories.isEmpty) {
      return Padding(
        padding: EdgeInsets.symmetric(vertical: 30.getSize),
        child: const Center(
          child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
        ),
      );
    }

    if (controller.categories.isEmpty) {
      return const SizedBox.shrink();
    }

    // Six fills two rows of three; the rest live behind "See all".
    final shown = controller.categories.take(6).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        CustomerSectionHeader(
          title: tr(StringRes.categoriesSection),
          actionLabel: controller.categories.length > 6
              ? tr(StringRes.seeAll)
              : null,
          onAction: controller.categories.length > 6
              ? () => openAllCategories(controller)
              : null,
        ),
        14.heightSpacer,
        GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          padding: EdgeInsets.zero,
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 3,
            mainAxisSpacing: 12.getSize,
            crossAxisSpacing: 12.getSize,
            childAspectRatio: 0.95,
          ),
          itemCount: shown.length,
          itemBuilder: (_, index) {
            final category = shown[index];

            return CustomerCategoryTile(
              icon: customerServiceIcon(category.name),
              name: category.name ?? '',
              onTap: () => openCategory(controller, category),
            );
          },
        ),
      ],
    );
  }

  // ── Popular services ────────────────────────────────────────────────────

  Widget popularSection(CustomerHomeController controller) {
    final services = controller.popularServices;

    if (services.isEmpty) {
      return const SizedBox.shrink();
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        CustomerSectionHeader(title: tr(StringRes.popularServices)),
        14.heightSpacer,
        SizedBox(
          height: 132.getSize,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: EdgeInsets.zero,
            itemCount: services.length,
            separatorBuilder: (_, _) => 12.widthSpacer,
            itemBuilder: (_, index) {
              final service = services[index];

              return CustomerServiceCard(
                icon: customerServiceIcon(service.category.name),
                name: service.subcategory.name ?? '',
                categoryName: service.category.name ?? '',
                onTap: () => openSubcategory(controller, service),
              );
            },
          ),
        ),
      ],
    );
  }

  // ── Vendors near you ────────────────────────────────────────────────────

  Widget nearbySection(CustomerHomeController controller) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        CustomerSectionHeader(
          title: tr(StringRes.vendorsNearYou),
          actionLabel: controller.nearbyVendors.isEmpty
              ? null
              : tr(StringRes.seeAll),
          onAction: controller.nearbyVendors.isEmpty
              ? null
              : () => openNearbySearch(controller),
        ),
        14.heightSpacer,
        if (controller.isLoadingVendors && controller.nearbyVendors.isEmpty)
          Padding(
            padding: EdgeInsets.symmetric(vertical: 20.getSize),
            child: const Center(
              child: CupertinoActivityIndicator(
                color: ServiceTokens.accentBright,
              ),
            ),
          )
        else if (controller.nearbyVendors.isEmpty)
          emptyNearby()
        else
          for (final vendor in controller.nearbyVendors) ...[
            CustomerVendorRow(
              name: vendor.businessName ?? '',
              rating: vendor.ratingAvg,
              ratingCount: vendor.ratingCount,
              imageUrl: vendor.shopPhotoUrl,
              badge: tr(StringRes.newVendorBadge),
              onTap: () => openVendor(controller, vendor),
            ),
            12.heightSpacer,
          ],
      ],
    );
  }

  Widget emptyNearby() {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.symmetric(vertical: 26.getSize, horizontal: 18.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(16.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Column(
        children: [
          const CustomerIconTile(icon: Icons.storefront_outlined, size: 52),
          14.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.noVendorsNearby),
            fontSize: 16,
            fontWeight: FontWeight.w800,
            color: ServiceTokens.text,
            textAlign: TextAlign.center,
          ),
          8.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.noVendorsNearbyDesc),
            fontSize: 12,
            color: ServiceTokens.muted,
            textAlign: TextAlign.center,
            maxLines: 3,
          ),
        ],
      ),
    );
  }

  // ── Navigation ──────────────────────────────────────────────────────────

  void openCategory(CustomerHomeController controller, CategoryModel category) {
    Get.to(() => CustomerSubcategoriesView(
          category: category,
          zoneId: controller.zone?.id,
          latitude: controller.latitude,
          longitude: controller.longitude,
          pincode: controller.pincode,
        ));
  }

  void openSubcategory(
    CustomerHomeController controller,
    PopularService service,
  ) {
    final id = service.subcategory.id;

    if (id == null) {
      return;
    }

    Get.to(() => VendorSearchView(
          subcategoryId: id,
          subcategoryName: service.subcategory.name,
          latitude: controller.latitude,
          longitude: controller.longitude,
          pincode: controller.pincode,
        ));
  }

  /// "See all" on the nearby list, and the search bar, both land on the
  /// same place: vendor search for a representative subcategory. There
  /// is no all-services search endpoint — matching is per-subcategory
  /// by design (SPEC section 4.4).
  void openNearbySearch(CustomerHomeController controller) {
    final service = controller.popularServices.firstOrNull;

    if (service == null) {
      return;
    }

    openSubcategory(controller, service);
  }

  /// The home search bar. Opens a real search over the loaded service
  /// tree — it used to jump straight to one hard-coded subcategory,
  /// which is why it never behaved like a search.
  void openServiceSearch(CustomerHomeController controller) {
    if (controller.categories.isEmpty) {
      return;
    }

    Get.to(() => CustomerServiceSearchView(
          categories: controller.categories,
          zoneId: controller.zone?.id,
          latitude: controller.latitude,
          longitude: controller.longitude,
          pincode: controller.pincode,
        ));
  }

  void openAllCategories(CustomerHomeController controller) {
    // Every category is already loaded, so "see all" is a sheet over
    // the grid rather than another screen and another fetch.
    Get.bottomSheet(
      Container(
        padding: EdgeInsets.fromLTRB(16.getSize, 12.getSize, 16.getSize, 24.getSize),
        decoration: BoxDecoration(
          color: ServiceTokens.card,
          borderRadius: BorderRadius.vertical(top: Radius.circular(22.getSize)),
        ),
        child: SafeArea(
          top: false,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  height: 4.getSize,
                  width: 46.getSize,
                  decoration: BoxDecoration(
                    color: ServiceTokens.stroke2,
                    borderRadius: BorderRadius.circular(3.getSize),
                  ),
                ),
              ),
              18.heightSpacer,
              CustomerSectionHeader(title: tr(StringRes.categoriesSection)),
              16.heightSpacer,
              Flexible(
                child: GridView.builder(
                  shrinkWrap: true,
                  padding: EdgeInsets.zero,
                  gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 3,
                    mainAxisSpacing: 12.getSize,
                    crossAxisSpacing: 12.getSize,
                    childAspectRatio: 0.95,
                  ),
                  itemCount: controller.categories.length,
                  itemBuilder: (_, index) {
                    final category = controller.categories[index];

                    return CustomerCategoryTile(
                      icon: customerServiceIcon(category.name),
                      name: category.name ?? '',
                      onTap: () {
                        Get.back();
                        openCategory(controller, category);
                      },
                    );
                  },
                ),
              ),
            ],
          ),
        ),
      ),
      isScrollControlled: true,
    );
  }

  void openVendor(CustomerHomeController controller, VendorSearchModel vendor) {
    final id = vendor.id;
    // Vendor detail wants the subcategory the customer arrived through,
    // so it can record the lead against it. The nearby rail is built
    // from one representative subcategory — the same one it searched.
    final subcategoryId = controller.popularServices.firstOrNull?.subcategory.id;

    if (id == null || subcategoryId == null) {
      return;
    }

    Get.to(() => VendorDetailView(
          vendorId: id,
          subcategoryId: subcategoryId,
          zoneId: controller.zone?.id,
          latitude: controller.latitude,
          longitude: controller.longitude,
        ));
  }
}
