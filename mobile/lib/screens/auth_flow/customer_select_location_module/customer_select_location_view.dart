import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../../../utils/place_service.dart';
import '../customer_current_location_module/customer_current_location_view.dart';
import '../customer_map_location_module/customer_map_location_view.dart';
import 'customer_select_location_controller.dart';

/// "Select location" (SPEC section 4.2) — type a place, or hand off to
/// GPS or the map.
///
/// Opened with `Get.to`, and returns the chosen [PlaceSuggestion]
/// through `Get.back(result:)`. The two hand-off screens return their
/// result through this one, so the caller only ever deals with this
/// screen's result regardless of which route the customer took.
class CustomerSelectLocationView extends StatelessWidget {
  const CustomerSelectLocationView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerSelectLocationController>(
      init: CustomerSelectLocationController(),
      dispose: (_) => Get.delete<CustomerSelectLocationController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          resizeToAvoidBottomInset: true,
          body: Column(
            children: [
              header(controller),
              Expanded(child: body(controller)),
            ],
          ),
        );
      },
    );
  }

  Widget header(CustomerSelectLocationController controller) {
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
                  text: tr(StringRes.selectLocationTitle),
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

  Widget searchField(CustomerSelectLocationController controller) {
    // A solid white field on the teal header, with dark ink in it. The
    // dark theme used a translucent fill and white text; both would be
    // near-invisible now that the canvas colour behind that translucency
    // is itself almost white.
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
                hintText: tr(StringRes.searchLocationHint),
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

  Widget body(CustomerSelectLocationController controller) {
    return ListView(
      padding: EdgeInsets.fromLTRB(16.getSize, 16.getSize, 16.getSize, 24.getSize),
      children: [
        CustomerGroup(
          children: [
            CustomerRow(
              icon: Icons.my_location,
              title: tr(StringRes.useCurrentLocation),
              subtitle: tr(StringRes.useCurrentLocationDesc),
              onTap: () => openAndReturn(const CustomerCurrentLocationView()),
            ),
            CustomerRow(
              icon: Icons.map_outlined,
              title: tr(StringRes.chooseOnMap),
              subtitle: tr(StringRes.chooseOnMapDesc),
              onTap: () => openAndReturn(const CustomerMapLocationView()),
            ),
          ],
        ),
        20.heightSpacer,
        if (controller.isSearching)
          Padding(
            padding: EdgeInsets.only(top: 30.getSize),
            child: const Center(
              child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
            ),
          )
        else if (controller.results.isNotEmpty) ...[
          CustomerGroupLabel(text: tr(StringRes.searchResultsLabel).toUpperCase()),
          12.heightSpacer,
          CustomerGroup(
            children: [
              for (final suggestion in controller.results)
                CustomerRow(
                  icon: Icons.location_on_outlined,
                  title: suggestion.title,
                  subtitle: suggestion.subtitle,
                  onTap: () => controller.selectSuggestion(suggestion),
                ),
            ],
          ),
          16.heightSpacer,
          attribution(),
        ] else if (controller.hasSearched)
          emptyResults(),
      ],
    );
  }

  Widget emptyResults() {
    return Padding(
      padding: EdgeInsets.only(top: 34.getSize),
      child: Column(
        children: [
          const CustomerIconTile(icon: Icons.search_off, size: 56),
          14.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.noPlacesFound),
            fontSize: 16,
            fontWeight: FontWeight.w800,
            color: ServiceTokens.text,
            textAlign: TextAlign.center,
          ),
          8.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.noPlacesFoundDesc),
            fontSize: 14,
            color: ServiceTokens.muted,
            textAlign: TextAlign.center,
            maxLines: 3,
          ),
        ],
      ),
    );
  }

  /// Google requires visible attribution wherever Places data is shown
  /// outside a Google map.
  Widget attribution() {
    return Center(
      child: BaseTextDMSans(
        text: tr(StringRes.poweredByGoogle),
        fontSize: 12,
        color: ServiceTokens.muted2,
      ),
    );
  }

  /// Opens a hand-off screen and, if it produced a place, closes this
  /// screen with the same result — so the original caller gets one
  /// answer no matter how deep the customer went.
  Future<void> openAndReturn(Widget screen) async {
    final result = await Get.to<PlaceSuggestion?>(() => screen);

    if (result != null) {
      Get.back(result: result);
    }
  }
}
