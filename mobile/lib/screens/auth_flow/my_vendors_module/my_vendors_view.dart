import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../../vendor_flow/add_vendor_module/add_vendor_view.dart';
import '../salesman_vendor_detail_module/salesman_vendor_detail_view.dart';
import 'my_vendors_controller.dart';

/// Salesman home, My Vendors tab (SPEC section 2.3).
///
/// Each row is a card rather than a line: a salesman's first question is
/// "who is paying and who still needs closing", so the plan, the quota
/// bars, the status and the expiry all belong on the card itself.
class MyVendorsView extends StatelessWidget {
  const MyVendorsView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<MyVendorsController>(
      init: MyVendorsController(),
      dispose: (_) => Get.delete<MyVendorsController>(),
      builder: (controller) {
        // Own Material rather than relying on the host Scaffold: the search
        // field needs one, and this tab is rendered standalone in tests.
        return Material(
          color: ServiceTokens.bg,
          child: Stack(
            children: [
              Positioned.fill(child: body(controller)),
              Positioned(
                right: 18.getSize,
                bottom: 20.getSize,
                child: addVendorFab(controller),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget body(MyVendorsController controller) {
    if (controller.isLoading && controller.vendors.isEmpty) {
      return const Center(
        child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
      );
    }

    if (controller.vendors.isEmpty) {
      return emptyState(controller);
    }

    final filtered = controller.filteredVendors;

    return RefreshIndicator(
      onRefresh: controller.fetchVendorsAPI,
      color: ServiceTokens.accent,
      backgroundColor: ServiceTokens.card,
      child: ListView(
        padding: EdgeInsets.fromLTRB(16.getSize, 14.getSize, 16.getSize, 96.getSize),
        children: [
          searchRow(controller),
          14.heightSpacer,
          sectionLabel(controller, filtered.length),
          12.heightSpacer,
          if (filtered.isEmpty)
            Padding(
              padding: EdgeInsets.symmetric(vertical: 30.getSize),
              child: Center(
                child: BaseTextDMSans(
                  text: StringRes.noVendorsMatch,
                  fontSize: 12,
                  color: ServiceTokens.muted2,
                  textAlign: TextAlign.center,
                ).tr(),
              ),
            )
          else
            for (final vendor in filtered) vendorCard(controller, vendor),
        ],
      ),
    );
  }

  Widget emptyState(MyVendorsController controller) {
    return Center(
      child: Padding(
        padding: EdgeInsets.all(24.getSize),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            BaseTextDMSans(
              text: StringRes.noVendorsYet,
              fontSize: 14,
              color: ServiceTokens.muted,
              textAlign: TextAlign.center,
            ).tr(),
            16.heightSpacer,
            BaseRaisedButton(
              onPressed: () => addVendor(controller),
              buttonText: StringRes.addVendorTitle,
              buttonColor: ServiceTokens.accent,
              isExpanded: false,
            ),
          ],
        ),
      ),
    );
  }

  Widget searchRow(MyVendorsController controller) {
    return ServicesSearchBar(
      controller: controller.searchController,
      hintText: tr(StringRes.searchVendorsHint),
      onChanged: controller.onSearchChanged,
      // Lit while anything is narrowing the list, and tapping it clears
      // both the query and the filter in one go.
      filterActive: controller.hasActiveFilter,
      onFilterTap: controller.clearFilters,
    );
  }

  Widget sectionLabel(MyVendorsController controller, int shown) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Flexible(
          child: BaseTextDMSans(
            text: '$shown ${tr(StringRes.vendorsCountLabel)}',
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: ServiceTokens.text,
            textAlign: TextAlign.start,
            maxLines: 1,
          ),
        ),
        // Doubles as the filter control: tapping narrows to vendors with
        // no live plan, which is the salesman's actual work queue.
        GestureDetector(
          onTap: controller.toggleUnsubscribedOnly,
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                controller.unsubscribedOnly
                    ? Icons.filter_alt
                    : Icons.filter_alt_outlined,
                size: 15.getSize,
                color: controller.unsubscribedOnly
                    ? ServiceTokens.accent
                    : ServiceTokens.muted,
              ),
              5.widthSpacer,
              BaseTextDMSans(
                text: controller.unsubscribedOnly
                    ? tr(StringRes.notSubscribed)
                    : tr(StringRes.sortRecent),
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: controller.unsubscribedOnly
                    ? ServiceTokens.accent
                    : ServiceTokens.muted,
                maxLines: 1,
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget vendorCard(MyVendorsController controller, SalesmanVendorModel vendor) {
    final subscribed = vendor.isSubscribed;

    return VendorCardShell(
      highlighted: subscribed,
      // A draft is an unfinished sale, not a record to read: tapping it
      // puts the salesman back into the onboarding where they left it
      // rather than on a details page with no way to continue. Everything
      // else is a finished vendor, so it still opens the detail screen.
      onTap: vendor.id == null
          ? null
          : vendor.isDraft
              ? () => controller.resumeDraftAPI(vendor)
              : () => Get.to(() => SalesmanVendorDetailView(vendorId: vendor.id!)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              InitialsAvatar(initials: vendor.initials, active: subscribed),
              12.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: vendor.businessName ?? '',
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    3.heightSpacer,
                    subtitleRow(vendor),
                  ],
                ),
              ),
              6.widthSpacer,
              Icon(Icons.chevron_right, size: 18.getSize, color: ServiceTokens.muted2),
            ],
          ),
          if (vendor.quota != null) ...[
            11.heightSpacer,
            MiniUsageBar(ratios: vendor.quota!.all.map((q) => q.ratio).toList()),
          ],
          13.heightSpacer,
          Divider(height: 1.getSize, thickness: 1.getSize, color: ServiceTokens.stroke),
          13.heightSpacer,
          footerRow(vendor),
        ],
      ),
    );
  }

  Widget subtitleRow(SalesmanVendorModel vendor) {
    // Keyed on having EVER had a plan, not on being currently subscribed:
    // the server deliberately keeps sending the last plan and a negative
    // days_to_expiry for a lapsed vendor, and "Silver · expired 4 days ago"
    // is precisely the row a salesman needs to act on. Gating this on the
    // live subscription would blank it to "No active plan" and lose the
    // reason to call them.
    if (vendor.planName == null) {
      return BaseTextDMSans(
        text: StringRes.noActivePlan,
        fontSize: 12,
        color: ServiceTokens.muted,
        textAlign: TextAlign.start,
        maxLines: 1,
      ).tr();
    }

    final expired = (vendor.daysToExpiry ?? 0) < 0;

    return Row(
      children: [
        PlanPill(label: vendor.planName!),
        6.widthSpacer,
        Flexible(
          child: BaseTextDMSans(
            text: expiryText(vendor.daysToExpiry),
            fontSize: 12,
            color: expired ? ColorRes.errorColor : ServiceTokens.muted,
            textAlign: TextAlign.start,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    );
  }

  Widget footerRow(SalesmanVendorModel vendor) {
    final subscribed = vendor.isSubscribed;

    return Row(
      children: [
        StatusTag(
          label: subscribed ? tr(StringRes.activeLabel) : tr(StringRes.notSubscribed),
          muted: !subscribed,
        ),
        const Spacer(),
        Flexible(
          child: BaseTextDMSans(
            text: subscribed && vendor.endDate != null
                ? '${tr(StringRes.expiresLabel)} ${vendor.endDate}'
                : tr(StringRes.subscribeAction),
            fontSize: 10,
            fontWeight: FontWeight.w600,
            color: subscribed ? ServiceTokens.muted : ServiceTokens.accentBright,
            textAlign: TextAlign.end,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    );
  }

  String expiryText(int? daysToExpiry) {
    if (daysToExpiry == null) {
      return '';
    }
    if (daysToExpiry < 0) {
      return '${tr(StringRes.expiredDaysAgo)} ${-daysToExpiry} ${tr(StringRes.daysAgoSuffix)}';
    }

    return '$daysToExpiry ${tr(StringRes.daysLeft)}';
  }

  Widget addVendorFab(MyVendorsController controller) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: () => addVendor(controller),
        borderRadius: BorderRadius.circular(16.getSize),
        child: Container(
          height: 52.getSize,
          padding: EdgeInsets.symmetric(horizontal: 20.getSize),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [ServiceTokens.accentBright, ServiceTokens.accent],
            ),
            borderRadius: BorderRadius.circular(16.getSize),
            boxShadow: [
              BoxShadow(
                color: ServiceTokens.accent.withValues(alpha: 0.5),
                blurRadius: 30.getSize,
                offset: Offset(0, 12.getSize),
              ),
            ],
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.add, size: 19.getSize, color: Colors.white),
              9.widthSpacer,
              BaseTextDMSans(
                text: StringRes.addVendorFab,
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: Colors.white,
              ).tr(),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> addVendor(MyVendorsController controller) async {
    await Get.to(() => const AddVendorView());
    controller.fetchVendorsAPI();
  }
}
