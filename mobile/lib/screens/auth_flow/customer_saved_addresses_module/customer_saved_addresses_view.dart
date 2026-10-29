import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'customer_saved_addresses_controller.dart';

/// Profile → My addresses — see [CustomerSavedAddressesController].
class CustomerSavedAddressesView extends StatelessWidget {
  const CustomerSavedAddressesView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerSavedAddressesController>(
      init: CustomerSavedAddressesController(),
      dispose: (_) => Get.delete<CustomerSavedAddressesController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: Column(
            children: [
              header(),
              Expanded(child: body(controller)),
            ],
          ),
        );
      },
    );
  }

  Widget header() {
    return CustomerHero(
      bottomPadding: 18.getSize,
      child: Row(
        children: [
          CustomerIconButton(icon: Icons.chevron_left, onTap: Get.back),
          Expanded(
            child: Column(
              children: [
                BaseTextDMSans(
                  text: tr(StringRes.myAddresses),
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: Colors.white,
                ),
                4.heightSpacer,
                BaseTextDMSans(
                  text: tr(StringRes.myAddressesDesc),
                  fontSize: 12,
                  color: Colors.white.withValues(alpha: 0.82),
                ),
              ],
            ),
          ),
          SizedBox(width: 42.getSize),
        ],
      ),
    );
  }

  Widget body(CustomerSavedAddressesController controller) {
    return RefreshIndicator(
      color: ServiceTokens.accent,
      onRefresh: controller.fetchAddressesAPI,
      child: ListView(
        padding: EdgeInsets.fromLTRB(16.getSize, 16.getSize, 16.getSize, 24.getSize),
        children: [
          CustomerGroup(
            children: [
              CustomerRow(
                icon: Icons.add_location_alt_outlined,
                title: tr(StringRes.addNewAddress),
                onTap: controller.addNewAddress,
              ),
            ],
          ),
          20.heightSpacer,
          if (controller.isLoading && controller.addresses.isEmpty)
            Padding(
              padding: EdgeInsets.symmetric(vertical: 40.getSize),
              child: const Center(child: CupertinoActivityIndicator(color: ServiceTokens.accent)),
            )
          else if (controller.addresses.isEmpty)
            emptyState(controller)
          else ...[
            CustomerGroupLabel(text: tr(StringRes.savedAddresses).toUpperCase()),
            12.heightSpacer,
            for (final address in controller.addresses) ...[
              CustomerAddressCard(
                label: address.label,
                title: address.displayLabel ?? '',
                address: address.fullAddress,
                isSelected: address.isDefault,
                selectedText: tr(StringRes.selectedBadge),
                editText: tr(StringRes.editAction),
                deleteText: tr(StringRes.deleteAction),
                onTap: () => controller.selectAddressAPI(address),
                onEdit: () => controller.editAddress(address),
                onDelete: () => controller.deleteAddressAPI(address),
              ),
              10.heightSpacer,
            ],
          ],
          16.heightSpacer,
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.lock_outline, size: 14.getSize, color: ServiceTokens.muted2),
              6.widthSpacer,
              Flexible(
                child: BaseTextDMSans(
                  text: tr(StringRes.addressSafeNote),
                  fontSize: 10,
                  color: ServiceTokens.muted,
                  maxLines: 2,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget emptyState(CustomerSavedAddressesController controller) {
    return Padding(
      padding: EdgeInsets.only(top: 24.getSize),
      child: Column(
        children: [
          const CustomerIconTile(icon: Icons.bookmark_border, size: 64),
          14.heightSpacer,
          BaseTextDMSans(
            text: tr(StringRes.noSavedAddresses),
            fontSize: 16,
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
          20.heightSpacer,
          CustomerPrimaryButton(
            label: tr(StringRes.addNewAddress),
            icon: Icons.add,
            onTap: controller.addNewAddress,
          ),
        ],
      ),
    );
  }
}
