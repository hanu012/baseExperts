import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'customer_set_location_controller.dart';

/// "Set your location" — see [CustomerSetLocationController].
///
/// Top to bottom, as in the patient app: header with search, "Use my
/// current location", search results while typing, then saved addresses.
class CustomerSetLocationView extends StatelessWidget {
  const CustomerSetLocationView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerSetLocationController>(
      init: CustomerSetLocationController(),
      dispose: (_) => Get.delete<CustomerSetLocationController>(),
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

  Widget header(CustomerSetLocationController controller) {
    return CustomerHero(
      bottomPadding: 20.getSize,
      child: Column(
        children: [
          Row(
            children: [
              CustomerIconButton(icon: Icons.chevron_left, onTap: Get.back),
              Expanded(
                child: Column(
                  children: [
                    BaseTextDMSans(
                      text: tr(StringRes.setYourLocation),
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                    ),
                    4.heightSpacer,
                    BaseTextDMSans(
                      text: tr(StringRes.setYourLocationDesc),
                      fontSize: 12,
                      color: Colors.white.withValues(alpha: 0.82),
                      maxLines: 2,
                    ),
                  ],
                ),
              ),
              SizedBox(width: 42.getSize),
            ],
          ),
          18.heightSpacer,
          searchField(controller),
        ],
      ),
    );
  }

  Widget searchField(CustomerSetLocationController controller) {
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
                child: Icon(Icons.close, color: ServiceTokens.muted, size: 19.getSize),
              ),
            ),
        ],
      ),
    );
  }

  Widget body(CustomerSetLocationController controller) {
    return RefreshIndicator(
      color: ServiceTokens.accent,
      onRefresh: controller.fetchAddressesAPI,
      child: ListView(
        padding: EdgeInsets.fromLTRB(16.getSize, 16.getSize, 16.getSize, 24.getSize),
        children: [
          CustomerGroup(
            children: [
              CustomerRow(
                icon: Icons.my_location,
                title: tr(StringRes.useCurrentLocation),
                subtitle: tr(StringRes.useCurrentLocationDesc),
                onTap: controller.useCurrentLocation,
              ),
            ],
          ),
          20.heightSpacer,
          if (controller.isSearching)
            Padding(
              padding: EdgeInsets.symmetric(vertical: 30.getSize),
              child: const Center(child: CupertinoActivityIndicator(color: ServiceTokens.accent)),
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
            12.heightSpacer,
            // Google requires attribution wherever Places results appear
            // outside a Google map.
            Center(
              child: BaseTextDMSans(
                text: tr(StringRes.poweredByGoogle),
                fontSize: 12,
                color: ServiceTokens.muted2,
              ),
            ),
            20.heightSpacer,
          ] else if (controller.hasSearched) ...[
            noResults(),
            20.heightSpacer,
          ],
          CustomerGroupLabel(text: tr(StringRes.savedAddresses).toUpperCase()),
          12.heightSpacer,
          savedAddresses(controller),
        ],
      ),
    );
  }

  Widget savedAddresses(CustomerSetLocationController controller) {
    if (controller.isLoadingAddresses && controller.addresses.isEmpty) {
      return Padding(
        padding: EdgeInsets.symmetric(vertical: 24.getSize),
        child: const Center(child: CupertinoActivityIndicator(color: ServiceTokens.accent)),
      );
    }

    if (controller.addresses.isEmpty) {
      return Padding(
        padding: EdgeInsets.only(top: 12.getSize),
        child: Column(
          children: [
            const CustomerIconTile(icon: Icons.bookmark_border, size: 56),
            12.heightSpacer,
            BaseTextDMSans(
              text: tr(StringRes.noSavedAddresses),
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: ServiceTokens.text,
            ),
            6.heightSpacer,
            BaseTextDMSans(
              text: tr(StringRes.noSavedAddressesDesc),
              fontSize: 12,
              color: ServiceTokens.muted,
              maxLines: 3,
            ),
          ],
        ),
      );
    }

    return Column(
      children: [
        for (final address in controller.addresses) ...[
          CustomerAddressCard(
            label: address.label,
            title: address.displayLabel ?? '',
            address: address.fullAddress,
            isSelected: address.isDefault,
            selectedText: tr(StringRes.selectedBadge),
            editText: tr(StringRes.editAction),
            deleteText: tr(StringRes.deleteAction),
            onTap: () => controller.selectSavedAddressAPI(address),
            onEdit: () => controller.editAddress(address),
            onDelete: () => controller.deleteAddressAPI(address),
          ),
          10.heightSpacer,
        ],
      ],
    );
  }

  Widget noResults() {
    return Column(
      children: [
        const CustomerIconTile(icon: Icons.search_off, size: 48),
        10.heightSpacer,
        BaseTextDMSans(
          text: tr(StringRes.noPlacesFound),
          fontSize: 14,
          fontWeight: FontWeight.w600,
          color: ServiceTokens.text,
        ),
        4.heightSpacer,
        BaseTextDMSans(
          text: tr(StringRes.noPlacesFoundDesc),
          fontSize: 12,
          color: ServiceTokens.muted,
          maxLines: 3,
        ),
      ],
    );
  }
}
