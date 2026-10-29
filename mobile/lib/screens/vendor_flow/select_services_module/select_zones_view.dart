import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'select_services_controller.dart';

/// Add Vendor, step 3 of 3 — coverage zones (SPEC 2.2, section 8).
///
/// Reads the SAME [SelectServicesController] the services step created
/// rather than owning one: the two steps are halves of a single selection
/// that is submitted in one call, so splitting the state would mean
/// stitching it back together at submit time. `Get.find` is safe here
/// because this screen is only ever pushed from the services step, which
/// stays alive underneath it in the navigator stack.
class SelectZonesView extends StatelessWidget {
  const SelectZonesView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SelectServicesController>(
      // No dispose here: the services step created the controller and its
      // GetBuilder removes it when that screen leaves. Deleting it here
      // broke Back-then-Continue — the services step was still open, so
      // the next push found no controller.
      init: Get.find<SelectServicesController>(),
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
            label: StringRes.continueLabel,
            countLabel: picked > 0 ? '$picked ${tr(StringRes.zonesUnit)}' : null,
            onPressed: () => onContinueTap(controller),
          ),
          children: bodyChildren(controller),
        );
      },
    );
  }

  List<Widget> bodyChildren(SelectServicesController controller) {
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

  Widget cityCard(SelectServicesController controller, ZoneModel city) {
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

  /// Top-level zones with no children are leaves in their own right
  /// (SPEC section 8), so they are selectable directly rather than being
  /// dropped for having no city to sit under.
  Widget looseZonesCard(SelectServicesController controller, List<ZoneModel> loose) {
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

  Widget zoneRow(SelectServicesController controller, ZoneModel zone) {
    final selected = controller.isZoneSelected(zone.id ?? -1);

    return ServicesRow(
      label: zone.name ?? '',
      selected: selected,
      enabled: selected || !controller.zoneQuotaReached,
      onTap: () => controller.toggleZone(zone),
    );
  }

  // ── Payment dialog (logic unchanged) ───────────────────────────────────

  void onContinueTap(SelectServicesController controller) {
    if (!controller.validateSelections()) {
      return;
    }

    // A bottom sheet rather than the AlertDialog this used to be: three
    // options each needing a name, an explanation and a selected state do
    // not fit a dialog's cramped content box, and the free-trial stepper
    // appearing inside one made it jump. A sheet also puts the choice
    // under the thumb, which matters for a salesman doing this standing
    // up in a shop.
    controller.refreshFreeTrialCapAPI();

    Get.bottomSheet(
      paymentModeSheet(controller),
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
    );
  }

  /// SPEC section 2.2: "choose payment mode -> confirmation -> single API
  /// call." Wrapped in its own GetBuilder so a tap updates the selection
  /// live without closing the sheet.
  Widget paymentModeSheet(SelectServicesController controller) {
    return GetBuilder<SelectServicesController>(
      builder: (controller) {
        return Container(
          decoration: BoxDecoration(
            color: ServiceTokens.card,
            borderRadius:
                BorderRadius.vertical(top: Radius.circular(26.getSize)),
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
                    text: StringRes.choosePaymentMode,
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: ServiceTokens.text,
                    textAlign: TextAlign.start,
                  ).tr(),
                  6.heightSpacer,
                  BaseTextDMSans(
                    text: StringRes.choosePaymentModeDesc,
                    fontSize: 14,
                    color: ServiceTokens.muted,
                    textAlign: TextAlign.start,
                    maxLines: 2,
                  ).tr(),
                  18.heightSpacer,
                  paymentModeOption(
                    controller,
                    'cash',
                    Icons.payments_outlined,
                    StringRes.paymentModeCash,
                    StringRes.paymentModeCashDesc,
                  ),
                  10.heightSpacer,
                  paymentModeOption(
                    controller,
                    'online',
                    Icons.credit_card,
                    StringRes.paymentModeOnline,
                    StringRes.paymentModeOnlineDesc,
                  ),
                  // A free trial is first-time only (SPEC 2.2); the server
                  // refuses it on a renewal too.
                  if (!controller.isRenewal) ...[
                    10.heightSpacer,
                    paymentModeOption(
                      controller,
                      'free',
                      Icons.card_giftcard,
                      StringRes.paymentModeFree,
                      StringRes.paymentModeFreeDesc,
                    ),
                  ],
                  if (controller.selectedPaymentMode == 'free' && !controller.isRenewal) ...[
                    14.heightSpacer,
                    freeTrialDurationPicker(controller),
                  ],
                  22.heightSpacer,
                  VendorPrimaryButton(
                    label: tr(StringRes.confirmSubscribe),
                    onTap: () {
                      // Closed first so the progress overlay is not
                      // stacked behind a sheet nobody can dismiss.
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
      },
    );
  }

  Widget freeTrialDurationPicker(SelectServicesController controller) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 12.getSize, vertical: 8.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.bg,
        borderRadius: BorderRadius.circular(10.getSize),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          BaseTextDMSans(
            text: StringRes.freeTrialDurationLabel,
            fontSize: 12,
            color: ServiceTokens.muted,
          ).tr(),
          6.heightSpacer,
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              IconButton(
                onPressed: controller.freeTrialDays > 1
                    ? () => controller.setFreeTrialDays(controller.freeTrialDays - 1)
                    : null,
                icon: const Icon(Icons.remove_circle_outline,
                    color: ServiceTokens.accentBright),
              ),
              BaseTextDMSans(
                text: '${controller.freeTrialDays} ${tr(StringRes.daysUnit)}',
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.text,
              ),
              IconButton(
                onPressed: controller.freeTrialDays < controller.freeTrialMaxDays
                    ? () => controller.setFreeTrialDays(controller.freeTrialDays + 1)
                    : null,
                icon: const Icon(Icons.add_circle_outline,
                    color: ServiceTokens.accentBright),
              ),
            ],
          ),
          BaseTextDMSans(
            text: '${tr(StringRes.freeTrialCappedAt)} '
                '${controller.freeTrialMaxDays} ${tr(StringRes.daysUnit)}',
            fontSize: 10,
            color: ServiceTokens.muted2,
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }

  /// One payment method: icon tile, name, one line of explanation, and the
  /// selected tick.
  ///
  /// Carries a description now because the three modes are not equally
  /// obvious — "Join as Free" in particular needed saying out loud that no
  /// money changes hands.
  Widget paymentModeOption(
    SelectServicesController controller,
    String mode,
    IconData icon,
    String labelKey,
    String descKey,
  ) {
    final selected = controller.selectedPaymentMode == mode;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: () => controller.selectPaymentMode(mode),
        borderRadius: BorderRadius.circular(16.getSize),
        child: Container(
          padding: EdgeInsets.all(14.getSize),
          decoration: BoxDecoration(
            color: selected ? ServiceTokens.card2 : ServiceTokens.bg,
            borderRadius: BorderRadius.circular(16.getSize),
            border: Border.all(
              color:
                  selected ? ServiceTokens.accentBright : ServiceTokens.stroke,
              width: selected ? 1.5 : 1,
            ),
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
                  icon,
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
                      text: labelKey,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                    ).tr(),
                    3.heightSpacer,
                    BaseTextDMSans(
                      text: descKey,
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
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color:
                      selected ? ServiceTokens.accentBright : Colors.transparent,
                  border: Border.all(
                    color: selected
                        ? ServiceTokens.accentBright
                        : ServiceTokens.stroke2,
                    width: 1.6,
                  ),
                ),
                child: selected
                    ? Icon(Icons.check, size: 14.getSize, color: Colors.white)
                    : null,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
