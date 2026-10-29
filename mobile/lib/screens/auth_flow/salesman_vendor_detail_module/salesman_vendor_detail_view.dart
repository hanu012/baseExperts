import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'salesman_vendor_detail_controller.dart';

/// One of the salesman's vendors, in full — profile, plan, quota usage and
/// the services/zones they actually bought (SPEC section 2.3).
///
/// Read-only: a salesman sells and inspects, they do not edit a live
/// vendor's selections. Changing those is quota-governed and belongs to the
/// subscribe/add-ons flow; deleting a vendor is an admin action. The
/// "Manage"/"Edit" affordances in the spec are therefore shown only where
/// they lead somewhere real.
class SalesmanVendorDetailView extends StatelessWidget {
  final int vendorId;

  const SalesmanVendorDetailView({super.key, required this.vendorId});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SalesmanVendorDetailController>(
      init: SalesmanVendorDetailController(vendorId: vendorId),
      dispose: (_) => Get.delete<SalesmanVendorDetailController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: body(controller),
        );
      },
    );
  }

  Widget body(SalesmanVendorDetailController controller) {
    if (controller.isLoading && controller.vendor == null) {
      return const Center(
        child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
      );
    }

    final vendor = controller.vendor;

    if (vendor == null) {
      return errorState(controller);
    }

    final subscription = vendor.activeSubscription;

    return RefreshIndicator(
      onRefresh: controller.fetchVendorAPI,
      color: ServiceTokens.accent,
      backgroundColor: ServiceTokens.card,
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          hero(vendor),
          Padding(
            padding: EdgeInsets.fromLTRB(16.getSize, 16.getSize, 16.getSize, 24.getSize),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                contactRow(vendor),
                16.heightSpacer,
                if (subscription == null)
                  notSubscribedPanel()
                else ...[
                  planBanner(vendor, subscription),
                  usagePanel(subscription),
                  itemsPanel(
                    tr(StringRes.servicesOfferedSection),
                    [
                      ...subscription.selectedSubcategories,
                      ...subscription.selectedCategories,
                    ],
                    zoneStyle: false,
                  ),
                  itemsPanel(
                    tr(StringRes.coverageZonesSection),
                    subscription.selectedZones,
                    zoneStyle: true,
                  ),
                ],
                SalesmanWideButton(
                  label: tr(StringRes.vendorDocumentsTitle),
                  icon: Icons.verified_user_outlined,
                  onTap: controller.openDocuments,
                ),
                12.heightSpacer,
                if (controller.canRenew) ...[
                  SalesmanWideButton(
                    label: tr(StringRes.renewPlan),
                    icon: Icons.autorenew,
                    onTap: controller.openRenew,
                  ),
                  12.heightSpacer,
                ],
                SalesmanWideButton(
                  label: tr(StringRes.deleteVendorAction),
                  danger: true,
                  // Surfaced because the spec asks for it, but a salesman
                  // cannot delete a vendor — there is no such endpoint and
                  // deleting one would strand its subscription, payments
                  // and commission. Says so rather than failing silently.
                  onTap: () => Utils.showToast(
                    tr(StringRes.deleteVendorUnavailable),
                    isError: true,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget errorState(SalesmanVendorDetailController controller) {
    return Center(
      child: Padding(
        padding: EdgeInsets.all(24.getSize),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            BaseTextDMSans(
              text: StringRes.somethingWentWrong,
              fontSize: 14,
              color: ServiceTokens.muted,
              textAlign: TextAlign.center,
            ).tr(),
            16.heightSpacer,
            BaseRaisedButton(
              onPressed: controller.fetchVendorAPI,
              buttonText: StringRes.retry,
              buttonColor: ServiceTokens.accent,
            ),
          ],
        ),
      ),
    );
  }

  // ── Hero ────────────────────────────────────────────────────────────────

  Widget hero(VendorMeModel vendor) {
    return SalesmanHero(
      bottomPadding: 26.getSize,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              HeroIconButton(icon: Icons.chevron_left, onTap: () => Get.back()),
              HeroIconButton(
                icon: Icons.more_vert,
                onTap: () => Utils.showToast(tr(StringRes.deleteVendorUnavailable)),
              ),
            ],
          ),
          18.heightSpacer,
          Row(
            children: [
              InitialsAvatar(
                initials: initialsOf(vendor.businessName),
                size: 56.getSize,
                radius: 17.getSize,
                onWhite: true,
              ),
              13.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: vendor.businessName ?? '',
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                      textAlign: TextAlign.start,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if (vendor.status != null) ...[
                      6.heightSpacer,
                      statusPill(vendor.status!),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  String initialsOf(String? name) {
    final parts =
        (name ?? '').trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();

    if (parts.isEmpty) {
      return '?';
    }
    if (parts.length == 1) {
      final one = parts.first;
      return (one.length >= 2 ? one.substring(0, 2) : one).toUpperCase();
    }

    return '${parts.first[0]}${parts[1][0]}'.toUpperCase();
  }

  Widget statusPill(String status) {
    // Mirrors the server's vendors.status enum (SPEC section 3.1).
    final live = status == 'active';

    return Container(
      padding: EdgeInsets.symmetric(horizontal: 10.getSize, vertical: 3.getSize),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: live ? 0.22 : 0.14),
        borderRadius: BorderRadius.circular(20.getSize),
        border: Border.all(color: Colors.white.withValues(alpha: 0.3)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            height: 6.getSize,
            width: 6.getSize,
            decoration: BoxDecoration(
              color: live ? ServiceTokens.green : Colors.white70,
              shape: BoxShape.circle,
            ),
          ),
          5.widthSpacer,
          BaseTextDMSans(
            text: status.replaceAll('_', ' '),
            fontSize: 10,
            fontWeight: FontWeight.w600,
            color: Colors.white,
          ),
        ],
      ),
    );
  }

  // ── Contact ─────────────────────────────────────────────────────────────

  Widget contactRow(VendorMeModel vendor) {
    return Row(
      children: [
        Expanded(
          child: contactButton(
            Icons.call_outlined,
            tr(StringRes.callAction),
            vendor.phone == null ? null : () => launchUri('tel:${vendor.phone}'),
          ),
        ),
        9.widthSpacer,
        Expanded(
          child: contactButton(
            Icons.mail_outline,
            tr(StringRes.emailAction),
            vendor.email == null ? null : () => launchUri('mailto:${vendor.email}'),
          ),
        ),
        9.widthSpacer,
        Expanded(
          child: contactButton(
            Icons.chat_bubble_outline,
            tr(StringRes.messageAction),
            vendor.phone == null ? null : () => launchUri('sms:${vendor.phone}'),
          ),
        ),
      ],
    );
  }

  Widget contactButton(IconData icon, String label, VoidCallback? onTap) {
    final enabled = onTap != null;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14.getSize),
        child: Container(
          padding: EdgeInsets.symmetric(vertical: 12.getSize),
          decoration: BoxDecoration(
            color: ServiceTokens.card,
            borderRadius: BorderRadius.circular(14.getSize),
            border: Border.all(color: ServiceTokens.stroke),
          ),
          child: Column(
            children: [
              Icon(
                icon,
                size: 18.getSize,
                color: enabled ? ServiceTokens.accentBright : ServiceTokens.muted2,
              ),
              5.heightSpacer,
              BaseTextDMSans(
                text: label,
                fontSize: 10,
                fontWeight: FontWeight.w600,
                color: enabled ? ServiceTokens.muted : ServiceTokens.muted2,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> launchUri(String uri) async {
    final parsed = Uri.parse(uri);

    if (await canLaunchUrl(parsed)) {
      await launchUrl(parsed);
      return;
    }

    Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
  }

  // ── Panels ──────────────────────────────────────────────────────────────

  Widget notSubscribedPanel() {
    return Container(
      margin: EdgeInsets.only(bottom: 13.getSize),
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Row(
        children: [
          Icon(Icons.info_outline, size: 18.getSize, color: ServiceTokens.muted),
          10.widthSpacer,
          Expanded(
            child: BaseTextDMSans(
              text: StringRes.notSubscribed,
              fontSize: 12,
              color: ServiceTokens.muted,
              textAlign: TextAlign.start,
            ).tr(),
          ),
        ],
      ),
    );
  }

  Widget planBanner(VendorMeModel vendor, ActiveSubscriptionModel subscription) {
    final days = subscription.daysRemaining;

    return Container(
      margin: EdgeInsets.only(bottom: 13.getSize),
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        // The header gradient — the one dark surface in the light theme,
        // which is what makes the white text below correct here.
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [ServiceTokens.heroTop, ServiceTokens.heroMid, ServiceTokens.heroBottom],
        ),
        borderRadius: BorderRadius.circular(18.getSize),
        boxShadow: ServiceTokens.cardShadow,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: BaseTextDMSans(
                  text: subscription.planName ?? '',
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                  color: Colors.white,
                  textAlign: TextAlign.start,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (days != null)
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    BaseTextDMSans(
                      // Negative means past end_date but still inside the
                      // grace window, which the server counts as active —
                      // say so rather than printing "-3".
                      text: days < 0 ? '${-days}' : '$days',
                      fontSize: 14,
                      fontWeight: FontWeight.w800,
                      color: days < 0 ? const Color(0xFFFECDCA) : Colors.white,
                    ),
                    BaseTextDMSans(
                      text: days < 0
                          ? '${tr(StringRes.expiredDaysAgo)} ${tr(StringRes.daysAgoSuffix)}'
                          : tr(StringRes.daysLeft),
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      color: Colors.white.withValues(alpha: 0.82),
                    ),
                  ],
                ),
            ],
          ),
          6.heightSpacer,
          BaseTextDMSans(
            text: [
              if (subscription.endDate != null)
                '${tr(StringRes.expiresLabel)} ${subscription.endDate}',
              if (vendor.ownerName != null)
                '${tr(StringRes.ownerLabel)}: ${vendor.ownerName}',
            ].join(' · '),
            fontSize: 12,
            fontWeight: FontWeight.w500,
            color: Colors.white.withValues(alpha: 0.82),
            textAlign: TextAlign.start,
            maxLines: 2,
          ),
        ],
      ),
    );
  }

  Widget usagePanel(ActiveSubscriptionModel subscription) {
    final rows = <Widget>[
      if (subscription.categories != null)
        usageRow(Icons.folder_outlined, tr(StringRes.categoriesSection), subscription.categories!),
      if (subscription.subcategories != null)
        usageRow(Icons.list_alt_outlined, tr(StringRes.subcategoriesCounted), subscription.subcategories!),
      if (subscription.zones != null)
        usageRow(Icons.place_outlined, tr(StringRes.coverageZonesSection), subscription.zones!),
      if (subscription.photos != null)
        usageRow(Icons.photo_camera_outlined, tr(StringRes.photosLabel), subscription.photos!),
      if (subscription.videos != null)
        usageRow(Icons.videocam_outlined, tr(StringRes.videosLabel), subscription.videos!, last: true),
    ];

    return SalesmanPanel(
      title: tr(StringRes.planUsage),
      child: Column(children: rows),
    );
  }

  Widget usageRow(
    IconData icon,
    String label,
    QuotaResourceModel quota, {
    bool last = false,
  }) {
    return UsageRow(
      icon: icon,
      label: label,
      used: quota.used ?? 0,
      max: quota.max ?? 0,
      last: last,
    );
  }

  Widget itemsPanel(
    String title,
    List<SelectedServiceItemModel> items, {
    required bool zoneStyle,
  }) {
    return SalesmanPanel(
      title: title,
      child: items.isEmpty
          ? BaseTextDMSans(
              text: StringRes.noServicesSelected,
              fontSize: 12,
              color: ServiceTokens.muted,
              textAlign: TextAlign.start,
            ).tr()
          : Wrap(
              spacing: 8.getSize,
              runSpacing: 8.getSize,
              children: [
                for (final item in items) chip(item.name ?? '', zoneStyle: zoneStyle),
              ],
            ),
    );
  }

  Widget chip(String label, {required bool zoneStyle}) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 13.getSize, vertical: 8.getSize),
      decoration: BoxDecoration(
        color: zoneStyle
            ? ServiceTokens.accentBright.withValues(alpha: 0.12)
            : ServiceTokens.card2,
        borderRadius: BorderRadius.circular(10.getSize),
        border: Border.all(
          color: zoneStyle
              ? ServiceTokens.accentBright.withValues(alpha: 0.25)
              : ServiceTokens.stroke2,
        ),
      ),
      child: BaseTextDMSans(
        text: label,
        fontSize: 12,
        fontWeight: FontWeight.w600,
        color: zoneStyle ? ServiceTokens.accent : ServiceTokens.text,
      ),
    );
  }
}
