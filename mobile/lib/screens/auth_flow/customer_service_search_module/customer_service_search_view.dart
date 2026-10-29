import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_search_module/vendor_search_view.dart';
import 'customer_service_search_controller.dart';

/// Service search (SPEC section 4 items 3-4) — type a service, tap it,
/// land on the vendors offering it near you.
///
/// The home search bar used to jump straight to one hard-coded
/// subcategory, which is why it never behaved like a search.
class CustomerServiceSearchView extends StatelessWidget {
  const CustomerServiceSearchView({
    super.key,
    required this.categories,
    required this.zoneId,
    required this.latitude,
    required this.longitude,
    required this.pincode,
  });

  final List<CategoryModel> categories;
  final int? zoneId;
  final double? latitude;
  final double? longitude;
  final String? pincode;

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerServiceSearchController>(
      init: CustomerServiceSearchController(categories: categories),
      dispose: (_) => Get.delete<CustomerServiceSearchController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          resizeToAvoidBottomInset: true,
          body: Column(
            children: [
              header(controller),
              Expanded(child: results(controller)),
            ],
          ),
        );
      },
    );
  }

  Widget header(CustomerServiceSearchController controller) {
    return CustomerHero(
      bottomPadding: 20.getSize,
      child: Column(
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
                  text: tr(StringRes.serviceSearchTitle),
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
          searchField(controller),
        ],
      ),
    );
  }

  Widget searchField(CustomerServiceSearchController controller) {
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
              autofocus: true,
              textInputAction: TextInputAction.search,
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
                hintText: tr(StringRes.customerSearchHint),
                hintStyle: TextStyle(
                  color: ServiceTokens.muted2,
                  fontSize: 14.getFontSize,
                  fontFamily: FontFamily.poppins,
                ),
              ),
            ),
          ),
          if (controller.hasQuery)
            GestureDetector(
              onTap: controller.clearQuery,
              behavior: HitTestBehavior.opaque,
              child: Padding(
                padding: EdgeInsets.only(left: 6.getSize),
                child: Icon(
                  Icons.close,
                  color: ServiceTokens.muted,
                  size: 19.getSize,
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget results(CustomerServiceSearchController controller) {
    if (controller.isSearching && controller.results.isEmpty) {
      return const Center(
        child: CupertinoActivityIndicator(color: ServiceTokens.accent),
      );
    }

    final hits = controller.visible;

    if (hits.isEmpty) {
      return empty(controller);
    }

    return ListView(
      padding: EdgeInsets.fromLTRB(16.getSize, 16.getSize, 16.getSize, 24.getSize),
      children: [
        CustomerGroupLabel(
          text: controller.hasQuery
              ? tr(StringRes.searchResultsLabel).toUpperCase()
              : tr(StringRes.allServicesLabel).toUpperCase(),
        ),
        12.heightSpacer,
        CustomerGroup(
          children: [
            for (final hit in hits)
              CustomerRow(
                icon: customerServiceIcon(hit.categoryName),
                title: hit.name,
                subtitle: hit.categoryName,
                onTap: () => openSubcategory(hit),
              ),
          ],
        ),
      ],
    );
  }

  Widget empty(CustomerServiceSearchController controller) {
    return Padding(
      padding: EdgeInsets.fromLTRB(28.getSize, 44.getSize, 28.getSize, 0),
      child: Column(
        children: [
          const CustomerIconTile(icon: Icons.search_off, size: 56),
          14.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.noServicesFound),
            fontSize: 16,
            fontWeight: FontWeight.w800,
            color: ServiceTokens.text,
            textAlign: TextAlign.center,
          ),
          8.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.noServicesFoundDesc),
            fontSize: 14,
            color: ServiceTokens.muted,
            textAlign: TextAlign.center,
            maxLines: 3,
          ),
        ],
      ),
    );
  }

  void openSubcategory(ServiceHit hit) {
    final id = hit.id;

    if (id == null) {
      return;
    }

    Get.to(() => VendorSearchView(
          subcategoryId: id,
          subcategoryName: hit.name,
          latitude: latitude,
          longitude: longitude,
          pincode: pincode,
        ));
  }
}
