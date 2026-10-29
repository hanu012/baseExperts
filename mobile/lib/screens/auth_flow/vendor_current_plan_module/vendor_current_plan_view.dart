import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_profile_module/vendor_profile_controller.dart';

/// "Current plan" — what the vendor is paying for and what it entitles
/// them to (SPEC section 3.2).
///
/// No controller of its own: this is a third reading of the same
/// GET /api/vendors/me the profile behind it already holds, so it binds
/// to [VendorProfileController] rather than firing an independent fetch
/// that could disagree with the screen it was opened from. Same reasoning
/// as the Services tab and the dashboard controller.
class VendorCurrentPlanView extends StatelessWidget {
  const VendorCurrentPlanView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorProfileController>(
      // Find, not init: the profile owns this controller's lifetime.
      init: Get.find<VendorProfileController>(),
      builder: (controller) {
        final subscription = controller.vendorMe?.activeSubscription;

        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: subscription == null
              ? const Center(
                  child: CupertinoActivityIndicator(
                    color: ServiceTokens.accentBright,
                  ),
                )
              : ListView(
                  padding: EdgeInsets.zero,
                  children: [
                    hero(),
                    Transform.translate(
                      offset: Offset(0, -34.getSize),
                      child: Padding(
                        padding: EdgeInsets.fromLTRB(16.getSize, 0, 16.getSize, 0),
                        child: Column(
                          children: [
                            planCard(subscription),
                            16.heightSpacer,
                            includedPanel(subscription),
                            24.heightSpacer,
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
        );
      },
    );
  }

  Widget hero() {
    return VendorHero(
      bottomPadding: 56.getSize,
      child: Row(
        children: [
          VendorIconButton(
            icon: Icons.chevron_left,
            onTap: Get.back,
            tooltip: tr(StringRes.backToLogin),
          ),
          Expanded(
            child: BaseTextDMSans(
              text: tr(StringRes.vendorCurrentPlanTitle),
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
    );
  }

  Widget planCard(ActiveSubscriptionModel subscription) {
    final days = subscription.daysRemaining ?? 0;

    return Container(
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const VendorIconTile(icon: Icons.workspace_premium_outlined),
              12.widthSpacer,
              Expanded(
                child: BaseTextDMSans(
                  text: subscription.planName ?? '',
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                  color: ServiceTokens.text,
                  textAlign: TextAlign.start,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              8.widthSpacer,
              activePill(),
            ],
          ),
          16.heightSpacer,
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: BaseTextDMSans(
                  text: tr(StringRes.vendorTimeRemaining).toUpperCase(),
                  fontSize: 10,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 1.0,
                  color: ServiceTokens.muted,
                  textAlign: TextAlign.start,
                ),
              ),
              BaseTextDMSans(
                text: '$days',
                fontSize: 18,
                fontWeight: FontWeight.w800,
                color: ServiceTokens.text,
              ),
              5.widthSpacer,
              Padding(
                padding: EdgeInsets.only(bottom: 3.getSize),
                child: BaseTextDMSans(
                  text: tr(StringRes.vendorDaysLeft),
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: ServiceTokens.muted,
                ),
              ),
            ],
          ),
          10.heightSpacer,
          // Term remaining, not quota — a full bar means freshly bought.
          VendorProgressBar(ratio: (days / 365).clamp(0.0, 1.0)),
        ],
      ),
    );
  }

  Widget activePill() {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 11.getSize, vertical: 5.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.green.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(20.getSize),
        border: Border.all(color: ServiceTokens.green.withValues(alpha: 0.32)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            height: 7.getSize,
            width: 7.getSize,
            decoration: const BoxDecoration(
              shape: BoxShape.circle,
              color: ServiceTokens.green,
            ),
          ),
          7.widthSpacer,
          BaseTextDMSans(
            text: tr(StringRes.vendorPlanActive),
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: ServiceTokens.green,
          ),
        ],
      ),
    );
  }

  /// The five allowances, each with what has actually been picked.
  Widget includedPanel(ActiveSubscriptionModel subscription) {
    return VendorPanel(
      title: tr(StringRes.vendorWhatsIncluded),
      trailing: BaseTextDMSans(
        text: tr(StringRes.vendorTapRowForDetails),
        fontSize: 12,
        color: ServiceTokens.muted2,
      ),
      child: Column(
        children: [
          _IncludedRow(
            icon: Icons.folder_outlined,
            label: tr(StringRes.categoriesSection),
            quota: subscription.categories,
            picked: subscription.selectedCategories
                .map((e) => e.name ?? '')
                .toList(),
          ),
          _IncludedRow(
            icon: Icons.list_alt_outlined,
            label: tr(StringRes.subcategoriesCounted),
            quota: subscription.subcategories,
            picked: subscription.selectedSubcategories
                .map((e) => e.name ?? '')
                .toList(),
          ),
          _IncludedRow(
            icon: Icons.location_on_outlined,
            label: tr(StringRes.zonesSection),
            quota: subscription.zones,
            picked:
                subscription.selectedZones.map((e) => e.name ?? '').toList(),
          ),
          // Photos and videos are counts, not named picks — the list
          // lives on the Portfolio tab, so these rows do not expand.
          _IncludedRow(
            icon: Icons.photo_outlined,
            label: tr(StringRes.photosLabel),
            quota: subscription.photos,
            picked: const [],
            expandable: false,
          ),
          _IncludedRow(
            icon: Icons.videocam_outlined,
            label: tr(StringRes.videosLabel),
            quota: subscription.videos,
            picked: const [],
            expandable: false,
            isLast: true,
          ),
        ],
      ),
    );
  }
}

/// One allowance: icon, name, used/max, a bar, and — when it has named
/// picks — an expandable list of them plus the free slots left.
class _IncludedRow extends StatefulWidget {
  const _IncludedRow({
    required this.icon,
    required this.label,
    required this.quota,
    required this.picked,
    this.expandable = true,
    this.isLast = false,
  });

  final IconData icon;
  final String label;
  final QuotaResourceModel? quota;
  final List<String> picked;
  final bool expandable;
  final bool isLast;

  @override
  State<_IncludedRow> createState() => _IncludedRowState();
}

class _IncludedRowState extends State<_IncludedRow> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    final used = widget.quota?.used ?? 0;
    final max = widget.quota?.max ?? 0;
    final free = (max - used).clamp(0, max);
    final ratio = max == 0 ? 0.0 : (used / max).clamp(0.0, 1.0);

    return Padding(
      padding: EdgeInsets.only(bottom: widget.isLast ? 0 : 16.getSize),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: widget.expandable
                  ? () => setState(() => _open = !_open)
                  : null,
              borderRadius: BorderRadius.circular(12.getSize),
              child: Row(
                children: [
                  VendorIconTile(icon: widget.icon, size: 36),
                  12.widthSpacer,
                  Expanded(
                    child: BaseTextDMSans(
                      text: widget.label,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                    ),
                  ),
                  BaseTextDMSans(
                    text: '$used / $max',
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: ServiceTokens.text,
                  ),
                  if (widget.expandable) ...[
                    4.widthSpacer,
                    Icon(
                      _open ? Icons.expand_less : Icons.chevron_right,
                      size: 18.getSize,
                      color: ServiceTokens.muted2,
                    ),
                  ],
                ],
              ),
            ),
          ),
          9.heightSpacer,
          VendorProgressBar(ratio: ratio),
          if (_open) ...[
            12.heightSpacer,
            expandedDetail(free),
          ],
        ],
      ),
    );
  }

  Widget expandedDetail(int free) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(14.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.bg,
        borderRadius: BorderRadius.circular(14.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          if (widget.picked.isEmpty)
            BaseTextDMSans(
              text: tr(StringRes.vendorNoneSelectedYet),
              fontSize: 12,
              color: ServiceTokens.muted2,
              textAlign: TextAlign.start,
            )
          else
            Wrap(
              spacing: 8.getSize,
              runSpacing: 8.getSize,
              children: [
                for (final name in widget.picked) VendorChip(label: name),
                // Dashed-looking placeholders for what is still unused,
                // so the headroom is visible rather than arithmetic.
                for (var i = 0; i < free.clamp(0, 3); i++) emptySlot(),
              ],
            ),
          if (free > 0) ...[
            12.heightSpacer,
            BaseTextDMSans(
              text: tr(StringRes.vendorSlotsAvailable, args: ['$free']),
              fontSize: 12,
              color: ServiceTokens.muted,
              textAlign: TextAlign.start,
            ),
          ],
        ],
      ),
    );
  }

  Widget emptySlot() {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 14.getSize, vertical: 9.getSize),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22.getSize),
        border: Border.all(color: ServiceTokens.stroke2),
      ),
      child: BaseTextDMSans(
        text: tr(StringRes.vendorEmptySlot),
        fontSize: 12,
        fontWeight: FontWeight.w500,
        color: ServiceTokens.muted2,
      ),
    );
  }
}
