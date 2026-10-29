import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_detail_module/vendor_detail_view.dart';
import 'vendor_search_controller.dart';

/// Vendor search results (SPEC section 4 item 4, task 5.3/5.4) — the
/// screen `CustomerSubcategoriesController.selectSubcategory()` leads
/// into. Same grid-of-cards-in-a-list shape as the rest of the app, one
/// vendor per row rather than a grid, since each row needs room for
/// name/rating/address.
class VendorSearchView extends StatelessWidget {
  final int subcategoryId;
  final String? subcategoryName;
  final double? latitude;
  final double? longitude;
  final String? pincode;

  const VendorSearchView({
    super.key,
    required this.subcategoryId,
    required this.subcategoryName,
    required this.latitude,
    required this.longitude,
    required this.pincode,
  });

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorSearchController>(
      init: VendorSearchController(
        subcategoryId: subcategoryId,
        subcategoryName: subcategoryName,
        latitude: latitude,
        longitude: longitude,
        pincode: pincode,
      ),
      dispose: (_) => Get.delete<VendorSearchController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ColorRes.backgroundColor,
          appBar: AppBar(
            title: BaseTextDMSans(
              text: subcategoryName ?? '',
              fontSize: 18,
              fontWeight: FontWeight.w600,
              color: ColorRes.secondaryColor,
            ),
          ),
          body: body(controller),
        );
      },
    );
  }

  Widget body(VendorSearchController controller) {
    if (controller.isLoading && controller.vendors.isEmpty) {
      return Center(child: CupertinoActivityIndicator(color: ColorRes.primaryColor));
    }

    if (controller.vendors.isEmpty) {
      return Center(
        child: BaseTextDMSans(
          text: StringRes.noVendorsFoundYet,
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

  Widget loadMoreButton(VendorSearchController controller) {
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

  Widget vendorCard(VendorSearchController controller, VendorSearchModel vendor) {
    return InkWell(
      borderRadius: BorderRadius.circular(10.getSize),
      onTap: vendor.id == null
          ? null
          : () => Get.to(
                () => VendorDetailView(
                  vendorId: vendor.id!,
                  subcategoryId: subcategoryId,
                  zoneId: controller.zone?.id,
                  latitude: latitude,
                  longitude: longitude,
                ),
              ),
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
            favoriteButton(controller, vendor),
          ],
        ),
      ),
    );
  }

  Widget favoriteButton(VendorSearchController controller, VendorSearchModel vendor) {
    return IconButton(
      onPressed: () => controller.toggleFavoriteAPI(vendor),
      icon: Icon(
        vendor.isFavorite ? Icons.favorite : Icons.favorite_border,
        color: vendor.isFavorite ? ColorRes.errorColor : ColorRes.grayColor,
        size: 22.getSize,
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
