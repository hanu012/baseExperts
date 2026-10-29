import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_detail_module/vendor_detail_view.dart';
import 'customer_favorites_controller.dart';

/// The customer's own favorited vendors (SPEC section 4 item 10). Same
/// card shape as VendorSearchView's own vendor card.
///
/// Reached two ways: as the middle tab of the home shell's bottom bar,
/// and pushed as its own route from the profile shortcut. [embedded]
/// distinguishes them — a tab must not draw its own app bar with a back
/// button that would pop the whole shell.
class CustomerFavoritesView extends StatelessWidget {
  const CustomerFavoritesView({super.key, this.embedded = false});

  /// True when hosted inside the home shell's bottom bar.
  final bool embedded;

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerFavoritesController>(
      init: CustomerFavoritesController(),
      dispose: (_) => Get.delete<CustomerFavoritesController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          appBar: AppBar(
            backgroundColor: ServiceTokens.bg,
            surfaceTintColor: Colors.transparent,
            elevation: 0,
            automaticallyImplyLeading: !embedded,
            centerTitle: embedded,
            title: BaseTextDMSans(
              text: StringRes.favoritesTab,
              fontSize: 18,
              fontWeight: FontWeight.w800,
              color: ServiceTokens.text,
            ).tr(),
          ),
          body: body(controller),
        );
      },
    );
  }

  Widget body(CustomerFavoritesController controller) {
    if (controller.isLoading && controller.vendors.isEmpty) {
      return Center(child: CupertinoActivityIndicator(color: ColorRes.primaryColor));
    }

    if (controller.vendors.isEmpty) {
      return Center(
        child: BaseTextDMSans(
          text: StringRes.noFavoritesYet,
          fontSize: 12,
          color: ColorRes.grayColor,
        ).tr(),
      );
    }

    return ListView.separated(
      padding: EdgeInsets.all(16.getSize),
      itemCount: controller.vendors.length + (controller.hasMore ? 1 : 0),
      separatorBuilder: (_, _) => 12.heightSpacer,
      itemBuilder: (_, index) {
        if (index == controller.vendors.length) {
          return loadMoreButton(controller);
        }

        return vendorCard(controller, controller.vendors[index]);
      },
    );
  }

  Widget loadMoreButton(CustomerFavoritesController controller) {
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

  Widget vendorCard(CustomerFavoritesController controller, VendorSearchModel vendor) {
    return InkWell(
      borderRadius: BorderRadius.circular(10.getSize),
      onTap: vendor.id == null
          ? null
          : () async {
              await Get.to(
                () => VendorDetailView(
                  vendorId: vendor.id!,
                  subcategoryId: null,
                  zoneId: null,
                  latitude: null,
                  longitude: null,
                ),
              );
              // The heart can be toggled on the vendor's page; reflect it.
              controller.fetchAPI();
            },
      child: Container(
        padding: EdgeInsets.all(12.getSize),
        decoration: BoxDecoration(
          color: ColorRes.surfaceElevatedColor,
          borderRadius: BorderRadius.circular(10.getSize),
          border: Border.all(color: ColorRes.borderColor),
        ),
        child: Row(
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(8.getSize),
              child: SizedBox(
                width: 56.getSize,
                height: 56.getSize,
                child: vendor.shopPhotoUrl != null
                    ? Image.network(
                        vendor.shopPhotoUrl!,
                        fit: BoxFit.cover,
                        errorBuilder: (_, _, _) => Container(
                          color: ColorRes.surfaceColor,
                          child: Icon(Icons.storefront, color: ColorRes.grayColor),
                        ),
                      )
                    : Container(
                        color: ColorRes.surfaceColor,
                        child: Icon(Icons.storefront, color: ColorRes.grayColor),
                      ),
              ),
            ),
            12.widthSpacer,
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  BaseTextDMSans(
                    text: vendor.businessName ?? '',
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: ColorRes.secondaryColor,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  4.heightSpacer,
                  ratingLabel(vendor),
                  if (vendor.address != null) ...[
                    2.heightSpacer,
                    BaseTextDMSans(
                      text: vendor.address!,
                      fontSize: 12,
                      color: ColorRes.grayColor,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ],
              ),
            ),
            IconButton(
              onPressed: () => controller.toggleFavoriteAPI(vendor),
              icon: Icon(Icons.favorite, color: ColorRes.errorColor, size: 22.getSize),
            ),
          ],
        ),
      ),
    );
  }

  Widget ratingLabel(VendorSearchModel vendor) {
    if ((vendor.ratingCount ?? 0) <= 0) {
      return BaseTextDMSans(
        text: StringRes.newVendorLabel,
        fontSize: 12,
        color: ColorRes.primaryColor,
      ).tr();
    }

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.star, color: ColorRes.warningColor, size: 14.getSize),
        4.widthSpacer,
        BaseTextDMSans(
          text: '${vendor.ratingAvg?.toStringAsFixed(1)} (${vendor.ratingCount})',
          fontSize: 12,
          color: ColorRes.grayColor,
        ),
      ],
    );
  }
}
