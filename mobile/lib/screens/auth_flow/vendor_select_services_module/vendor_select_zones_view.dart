import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_select_services_controller.dart';

/// Vendor self-service, step 3 of 3 — coverage zones (SPEC section 3.2).
///
/// Reads the SAME [VendorSelectServicesController] the services step
/// created rather than owning one: the two steps are halves of a single
/// selection submitted in one call. `Get.find` is safe because this screen
/// is only ever pushed from the services step, which stays alive beneath it.
class VendorSelectZonesView extends StatelessWidget {
  const VendorSelectZonesView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorSelectServicesController>(
      // No dispose here: the services step created the controller and its
      // GetBuilder removes it when that screen leaves. Deleting it here
      // broke Back-then-Continue — the services step was still open, so
      // the next push found no controller.
      init: Get.find<VendorSelectServicesController>(),
      builder: (controller) {
        final picked = controller.selectedZoneIds.length;

        return ServicesPage(
          header: const ServicesHeader(
            title: StringRes.coverageZonesTitle,
            subtitle: StringRes.coverageZonesDesc,
            step: 3,
            totalSteps: 3,
          ),
          footer: ServicesFooter(
            hint: tr(StringRes.zonesCountTowardLimit),
            label: controller.isAddingMore
                ? StringRes.saveChanges
                : StringRes.confirmSubscribe,
            countLabel: picked > 0 ? '$picked ${tr(StringRes.zonesUnit)}' : null,
            onPressed: controller.isAddingMore
                ? controller.addServicesAPI
                : () => onSubscribeTap(controller),
          ),
          children: bodyChildren(controller),
        );
      },
    );
  }

  // ── Payment confirmation ────────────────────────────────────────────────

  /// Validates, then asks the vendor to confirm what they are about to pay
  /// before the subscription call goes out.
  ///
  /// NOT the salesman app's payment-mode chooser: that screen offers cash,
  /// online and free because a salesman may take any of the three. A
  /// vendor subscribing themselves has exactly one legal mode —
  /// StoreSubscriptionRequest rejects anything but `online` for the vendor
  /// role (SPEC section 3.2) — so there is nothing to choose between. What
  /// was missing here was the confirmation itself: the amount used to
  /// never appear, and a single tap on the footer committed the purchase.
  void onSubscribeTap(VendorSelectServicesController controller) {
    if (!controller.validateSelections()) {
      return;
    }

    // Same bottom sheet the salesman flow uses, so confirming a
    // subscription looks and behaves the same in both apps.
    Get.bottomSheet(
      paymentSheet(controller),
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
    );
  }

  /// The vendor's confirmation sheet.
  ///
  /// Shows ONE method rather than the salesman's three. That is not a
  /// trimmed-down copy — StoreSubscriptionRequest rejects anything but
  /// `online` for the vendor role (SPEC section 3.2), so cash and free
  /// would be choices the server refuses. The card is rendered in the
  /// selected state to say which method applies, not to invite a choice
  /// that does not exist.
  Widget paymentSheet(VendorSelectServicesController controller) {
    final plan = controller.plan;

    return Container(
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.vertical(top: Radius.circular(26.getSize)),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(
            20.getSize,
            12.getSize,
            20.getSize,
            18.getSize,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  height: 4.getSize,
                  width: 44.getSize,
                  decoration: BoxDecoration(
                    color: ServiceTokens.stroke2,
                    borderRadius: BorderRadius.circular(4.getSize),
                  ),
                ),
              ),
              18.heightSpacer,
              BaseTextDMSans(
                text: tr(StringRes.vendorConfirmPaymentTitle),
                fontSize: 18,
                fontWeight: FontWeight.w800,
                color: ServiceTokens.text,
                textAlign: TextAlign.start,
              ),
              6.heightSpacer,
              BaseTextDMSans(
                text: tr(StringRes.vendorConfirmPaymentBody, args: [
                  plan.name ?? '',
                  '${plan.durationDays ?? 0}',
                ]),
                fontSize: 14,
                color: ServiceTokens.muted,
                textAlign: TextAlign.start,
                maxLines: 2,
              ),
              18.heightSpacer,

              // The amount, given its own row so it is the thing the eye
              // lands on before the confirm button.
              Container(
                width: double.infinity,
                padding: EdgeInsets.all(16.getSize),
                decoration: BoxDecoration(
                  color: ServiceTokens.bg,
                  borderRadius: BorderRadius.circular(16.getSize),
                  border: Border.all(color: ServiceTokens.stroke),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: BaseTextDMSans(
                        text: tr(StringRes.vendorAmountDue),
                        fontSize: 14,
                        color: ServiceTokens.muted,
                        textAlign: TextAlign.start,
                      ),
                    ),
                    BaseTextDMSans(
                      text: Utils.rupeesWhole(plan.pricePaise ?? 0),
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: ServiceTokens.text,
                    ),
                  ],
                ),
              ),
              12.heightSpacer,

              onlineMethodCard(),

              22.heightSpacer,
              VendorPrimaryButton(
                label: tr(StringRes.vendorPayNow, args: [
                  Utils.rupeesWhole(plan.pricePaise ?? 0),
                ]),
                onTap: () {
                  // Closed first so the progress overlay is not stacked
                  // behind a sheet nobody can dismiss.
                  Get.back();
                  controller.subscribeAPI();
                },
              ),
              8.heightSpacer,
              Center(
                child: TextButton(
                  onPressed: Get.back,
                  child: BaseTextDMSans(
                    text: StringRes.cancel,
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: ServiceTokens.muted,
                  ).tr(),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// The single available method, shown in the salesman sheet's card
  /// shape so the two flows read as one product.
  Widget onlineMethodCard() {
    return Container(
      padding: EdgeInsets.all(14.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card2,
        borderRadius: BorderRadius.circular(16.getSize),
        border: Border.all(color: ServiceTokens.accentBright, width: 1.5),
      ),
      child: Row(
        children: [
          Container(
            height: 42.getSize,
            width: 42.getSize,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: ServiceTokens.accent.withValues(alpha: 0.18),
              borderRadius: BorderRadius.circular(13.getSize),
              border: Border.all(
                color: ServiceTokens.accent.withValues(alpha: 0.28),
              ),
            ),
            child: Icon(
              Icons.credit_card,
              size: 19.getSize,
              color: ServiceTokens.accentBright,
            ),
          ),
          13.widthSpacer,
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                BaseTextDMSans(
                  text: StringRes.paymentModeOnline,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: ServiceTokens.text,
                  textAlign: TextAlign.start,
                ).tr(),
                3.heightSpacer,
                BaseTextDMSans(
                  text: StringRes.vendorConfirmPaymentOnlineNote,
                  fontSize: 12,
                  color: ServiceTokens.muted,
                  textAlign: TextAlign.start,
                  maxLines: 2,
                ).tr(),
              ],
            ),
          ),
          10.widthSpacer,
          Container(
            height: 24.getSize,
            width: 24.getSize,
            alignment: Alignment.center,
            decoration: const BoxDecoration(
              shape: BoxShape.circle,
              color: ServiceTokens.accentBright,
            ),
            child: Icon(Icons.check, size: 14.getSize, color: Colors.white),
          ),
        ],
      ),
    );
  }

  List<Widget> bodyChildren(VendorSelectServicesController controller) {
    final searchBar = ServicesSearchBar(
      controller: controller.zoneSearchController,
      hintText: tr(StringRes.searchAnAreaHint),
      onChanged: controller.onZoneSearchChanged,
      filterActive: controller.zoneSearchQuery.isNotEmpty,
      onFilterTap: () {
        controller.zoneSearchController.clear();
        controller.onZoneSearchChanged('');
      },
    );

    if (controller.isLoading && controller.zones.isEmpty) {
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

    final cities = controller.filteredZoneCities;
    final loose = controller.visibleStandaloneZones;

    return [
      searchBar,
      14.heightSpacer,
      ServicesStatRow(
        left: ServicesStatTile(
          value: '${controller.selectedZoneIds.length}',
          suffix: '/ ${controller.maxZones}',
          label: tr(StringRes.zonesInThisPlan),
        ),
        right: ServicesStatTile(
          value: '${controller.zonesRemaining}',
          suffix: tr(StringRes.leftLabel),
          label: tr(StringRes.stillAvailableLabel),
        ),
      ),
      16.heightSpacer,
      if (cities.isEmpty && loose.isEmpty)
        ServicesEmptyState(message: tr(StringRes.noMatchingZones))
      else ...[
        for (final city in cities) cityCard(controller, city),
        if (loose.isNotEmpty) looseZonesCard(controller, loose),
      ],
    ];
  }

  Widget cityCard(VendorSelectServicesController controller, ZoneModel city) {
    final visible = controller.visibleZonesIn(city);
    final picked = controller.selectedCountInCity(city);
    final total = city.children.length;
    final allPicked = total > 0 && picked == total;

    return ServicesGroupCard(
      header: ServicesGroupHead(
        title: city.name ?? '',
        countLine: '$total ${tr(StringRes.areasAvailable)}',
        fallbackIcon: Icons.place_outlined,
        badge: picked > 0
            ? ServicesBadge(
                label: allPicked
                    ? tr(StringRes.allLabel)
                    : '$picked ${tr(StringRes.pickedSuffix)}',
              )
            : const ServicesBadge(label: '0', muted: true),
      ),
      rows: [
        for (final zone in visible) zoneRow(controller, zone),
      ],
      footer: visible.isNotEmpty
          ? ServicesCardFooter(
              actionLabel: tr(StringRes.selectAllLabel),
              actionEnabled: !allPicked && !controller.zoneQuotaReached,
              onAction: () => controller.selectAllZonesIn(city),
              trailing: '$picked ${tr(StringRes.of)} $total '
                  '${tr(StringRes.selectedSuffix)}',
            )
          : null,
    );
  }

  Widget looseZonesCard(
    VendorSelectServicesController controller,
    List<ZoneModel> loose,
  ) {
    final picked =
        loose.where((zone) => controller.isZoneSelected(zone.id ?? -1)).length;

    return ServicesGroupCard(
      header: ServicesGroupHead(
        title: tr(StringRes.zonesSection),
        countLine: '${loose.length} ${tr(StringRes.areasAvailable)}',
        fallbackIcon: Icons.place_outlined,
        badge: picked > 0
            ? ServicesBadge(label: '$picked ${tr(StringRes.pickedSuffix)}')
            : const ServicesBadge(label: '0', muted: true),
      ),
      rows: [
        for (final zone in loose) zoneRow(controller, zone),
      ],
    );
  }

  Widget zoneRow(VendorSelectServicesController controller, ZoneModel zone) {
    final id = zone.id ?? -1;
    final selected = controller.isZoneSelected(id);

    return ServicesRow(
      label: zone.name ?? '',
      selected: selected,
      enabled: selected || !controller.zoneQuotaReached,
      onTap: () => controller.toggleZone(zone),
    );
  }
}
