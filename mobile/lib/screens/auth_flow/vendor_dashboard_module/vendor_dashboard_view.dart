import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_portfolio_module/vendor_portfolio_view.dart';
import '../vendor_leads_module/vendor_leads_view.dart';
import '../vendor_profile_module/vendor_profile_view.dart';
import '../vendor_reviews_module/vendor_reviews_view.dart';
import '../vendor_select_plan_module/vendor_select_plan_view.dart';
import '../vendor_services_module/vendor_services_view.dart';
import 'vendor_dashboard_controller.dart';

/// The vendor app's shell (SPEC section 3.2/3.9): a five-destination
/// bottom bar over Overview, Services, Portfolio, Profile and Reviews,
/// plus the Overview body itself.
///
/// A bottom bar rather than the scrolling top tab strip this screen used
/// to carry — five labels do not fit across a phone as tabs, and a
/// destination you have to scroll sideways to find is one nobody visits.
///
/// Overview and Services stay on the SAME controller/single fetch — both
/// are views over the exact same GET /api/vendors/me response (quota bars
/// vs. the actual item list), so splitting them would mean two independent
/// fetches of the same data, able to disagree with each other mid-session.
/// Portfolio, Profile and Reviews each own genuinely independent data
/// (their own list, their own endpoint), so each keeps its own module and
/// controller.
class VendorDashboardView extends StatelessWidget {
  const VendorDashboardView({super.key});

  /// Profile sits where Leads used to, at the client's request. The leads
  /// screen and its controller are untouched — only the way in is gone —
  /// so restoring it is this list plus the matching body below.
  ///
  /// Profile being a destination is also why the hero no longer carries a
  /// person button: two entry points to one screen, where one of them
  /// pushes a second copy over the shell, is exactly the arrangement that
  /// caused the disposed-controller crash earlier in this app.
  static const List<VendorNavItem> _destinations = [
    VendorNavItem(icon: Icons.grid_view_rounded, label: StringRes.overviewTab),
    VendorNavItem(icon: Icons.layers_outlined, label: StringRes.servicesTab),
    VendorNavItem(icon: Icons.photo_library_outlined, label: StringRes.portfolioTab),
    VendorNavItem(icon: Icons.person_outline, label: StringRes.vendorProfileTitle),
    VendorNavItem(icon: Icons.star_border, label: StringRes.reviewsTab),
  ];

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorDashboardController>(
      init: VendorDashboardController(),
      dispose: (_) => Get.delete<VendorDashboardController>(),
      builder: (controller) {
        final subscription = controller.vendorMe?.activeSubscription;

        // Describes the DATA, not the request in flight. Folding
        // `!isLoading` in here meant a pull-to-refresh — which keeps the
        // previous vendorMe and only flips isLoading — reported "no
        // subscription" for the length of the round trip, so the whole
        // dashboard and its bottom bar were replaced by the empty state
        // and then replaced back. Refreshing a screen must not make it
        // claim the subscription vanished.
        final hasSubscription = subscription != null;

        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          bottomNavigationBar: hasSubscription
              ? VendorBottomNav(
                  items: _destinations
                      .map((d) => VendorNavItem(icon: d.icon, label: tr(d.label)))
                      .toList(),
                  currentIndex: controller.currentTab,
                  onTap: controller.selectTab,
                )
              : null,
          body: body(controller, hasSubscription),
        );
      },
    );
  }

  Widget body(VendorDashboardController controller, bool hasSubscription) {
    if (controller.isLoading && controller.vendorMe == null) {
      return const Center(
        child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
      );
    }

    if (!hasSubscription) {
      return noActiveSubscriptionState(controller);
    }

    // IndexedStack, not a swap: each destination keeps its scroll position
    // and its already-fetched list when you come back to it, which a plain
    // conditional would throw away on every tap.
    return IndexedStack(
      index: controller.currentTab,
      // Must stay in step with _destinations — an IndexedStack is
      // positional, so a body with no matching tab would silently shift
      // every destination after it by one.
      children: [
        overview(controller),
        const VendorServicesView(),
        const VendorPortfolioView(),
        const VendorProfileView(embedded: true),
        const VendorReviewsView(),
      ],
    );
  }

  // ── Overview ────────────────────────────────────────────────────────────

  Widget overview(VendorDashboardController controller) {
    final subscription = controller.vendorMe!.activeSubscription!;
    final upgrade = controller.upgradePlan;

    return RefreshIndicator(
      onRefresh: controller.fetchVendorMeAPI,
      color: ServiceTokens.accentBright,
      backgroundColor: ServiceTokens.card,
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          hero(controller),
          Transform.translate(
            // The plan card lifts into the hero's lower edge; the hero
            // pads itself by the same amount so the overlap costs no
            // layout height.
            offset: Offset(0, -34.getSize),
            child: Padding(
              padding: EdgeInsets.fromLTRB(16.getSize, 0, 16.getSize, 0),
              child: Column(
                children: [
                  planCard(subscription),
                  16.heightSpacer,
                  if (controller.documentsNeedingAttention.isNotEmpty) ...[
                    documentsNotice(controller),
                    16.heightSpacer,
                  ],
                  // Admin switch "Call tracking in apps".
                  if (Injector.callTrackingEnabled) ...[
                    recentCallsCard(controller),
                    16.heightSpacer,
                  ],
                  usagePanel(subscription),
                  // "Need more room?" is hidden for now at the client's
                  // request. upsellCard and upgradePlan are left in place
                  // so restoring it is this condition alone.
                  if (Constants.showVendorUpsell && upgrade != null) ...[
                    16.heightSpacer,
                    upsellCard(controller, upgrade),
                  ],
                  24.heightSpacer,
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// "Action needed on your documents" — a document sent back, expired, or
  /// newly compulsory. Tapping opens the documents screen.
  Widget documentsNotice(VendorDashboardController controller) {
    return InkWell(
      key: const ValueKey('documentsNotice'),
      onTap: controller.openDocuments,
      borderRadius: BorderRadius.circular(14.getSize),
      child: Container(
        padding: EdgeInsets.all(14.getSize),
        decoration: BoxDecoration(
          color: ColorRes.warningColor.withValues(alpha: 0.10),
          borderRadius: BorderRadius.circular(14.getSize),
          border: Border.all(color: ColorRes.warningColor.withValues(alpha: 0.35)),
        ),
        child: Row(
          children: [
            Icon(Icons.assignment_late_outlined, size: 22.getSize, color: ColorRes.warningColor),
            12.widthSpacer,
            Expanded(
              child: BaseTextDMSans(
                text: tr(
                  StringRes.documentsNeedAttention,
                  args: [controller.documentsNeedingAttention.join(', ')],
                ),
                fontSize: 12,
                fontWeight: FontWeight.w500,
                color: ColorRes.secondaryColor,
                textAlign: TextAlign.start,
                maxLines: 4,
              ),
            ),
            8.widthSpacer,
            Icon(Icons.chevron_right, size: 20.getSize, color: ColorRes.grayColor),
          ],
        ),
      ),
    );
  }

  Widget hero(VendorDashboardController controller) {
    return VendorHero(
      bottomPadding: 56.getSize,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          VendorBrandRow(
            initials: controller.initials,
            businessName: controller.vendorMe?.businessName ?? '',
            photoUrl: controller.vendorMe?.avatarUrl,
            actions: [
              // Profile is a bottom-bar destination now, so the hero
              // keeps only sign-out.
              VendorIconButton(
                icon: Icons.logout,
                onTap: controller.logoutAPI,
                tooltip: tr(StringRes.signOut),
              ),
            ],
          ),
          18.heightSpacer,
          VendorHeroTitle(
            eyebrow: tr(StringRes.vendorDashboardEyebrow),
            title: tr(StringRes.vendorOverviewTitle),
          ),
        ],
      ),
    );
  }

  /// Today's calls, average call length, the last three leads, and a way
  /// into the full Leads list.
  Widget recentCallsCard(VendorDashboardController controller) {
    final summary = controller.callsSummary;
    final average = Utils.approxCallLength(summary?.averageCallSeconds);

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
              const VendorIconTile(icon: Icons.phone_in_talk_outlined),
              12.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    BaseTextDMSans(
                      text: tr(StringRes.recentCalls),
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                    ),
                    2.heightSpacer,
                    BaseTextDMSans(
                      text: [
                        '${summary?.callsToday ?? 0} ${tr(StringRes.callsToday)}',
                        if (average.isNotEmpty) '${tr(StringRes.avgCallLength)} $average',
                      ].join(' · '),
                      fontSize: 12,
                      color: ServiceTokens.muted,
                      textAlign: TextAlign.start,
                    ),
                  ],
                ),
              ),
              TextButton(
                onPressed: () => Get.to(() => const VendorLeadsView(standalone: true)),
                child: BaseTextDMSans(
                  text: tr(StringRes.seeAll),
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: ServiceTokens.accent,
                ),
              ),
            ],
          ),
          if (controller.recentLeads.isEmpty) ...[
            10.heightSpacer,
            BaseTextDMSans(
              text: tr(StringRes.noLeadsYet),
              fontSize: 12,
              color: ServiceTokens.muted,
              textAlign: TextAlign.start,
            ),
          ] else
            for (final lead in controller.recentLeads) recentCallRow(lead),
        ],
      ),
    );
  }

  Widget recentCallRow(LeadModel lead) {
    final isWhatsapp = lead.channel == 'whatsapp';
    final when = DateTime.tryParse(lead.createdAt ?? '')?.toLocal();
    final length = Utils.approxCallLength(lead.callDurationSeconds);

    return Padding(
      padding: EdgeInsets.only(top: 12.getSize),
      child: Row(
        children: [
          Icon(isWhatsapp ? Icons.chat_outlined : Icons.call_outlined, size: 18.getSize, color: ServiceTokens.accent),
          10.widthSpacer,
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                BaseTextDMSans(
                  text: lead.customerName ?? '-',
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: ServiceTokens.text,
                  textAlign: TextAlign.start,
                  maxLines: 1,
                ),
                BaseTextDMSans(
                  text: [lead.subcategoryName, lead.zoneName].whereType<String>().join(' · '),
                  fontSize: 12,
                  color: ServiceTokens.muted,
                  textAlign: TextAlign.start,
                  maxLines: 1,
                ),
              ],
            ),
          ),
          8.widthSpacer,
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              BaseTextDMSans(
                text: when == null ? '' : DateFormat('dd MMM, hh:mm a').format(when),
                fontSize: 10,
                color: ServiceTokens.muted,
              ),
              BaseTextDMSans(
                text: isWhatsapp ? tr(StringRes.whatsappButton) : length,
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.text,
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Current plan and how much of its term is left.
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
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const VendorIconTile(icon: Icons.workspace_premium_outlined),
              12.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: tr(StringRes.vendorCurrentPlan).toUpperCase(),
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      letterSpacing: 1.0,
                      color: ServiceTokens.muted,
                      textAlign: TextAlign.start,
                    ),
                    3.heightSpacer,
                    BaseTextDMSans(
                      text: subscription.planName ?? '',
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              8.widthSpacer,
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                mainAxisSize: MainAxisSize.min,
                children: [
                  BaseTextDMSans(
                    text: '$days',
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: ServiceTokens.text,
                  ),
                  BaseTextDMSans(
                    text: tr(StringRes.vendorDaysLeft),
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: ServiceTokens.muted,
                  ),
                ],
              ),
            ],
          ),
          14.heightSpacer,
          // Term remaining, not quota: a full bar means the subscription
          // was just bought and empties as it runs out.
          VendorProgressBar(ratio: termRatio(subscription)),
          // The renewal window: the last 15 days (when the expiry reminders
          // start) and grace. Days left are carried over, so renewing early
          // costs nothing.
          if (days <= 15) ...[
            14.heightSpacer,
            VendorPrimaryButton(
              label: tr(StringRes.renewPlan),
              icon: Icons.autorenew,
              onTap: () => Get.to(() => const VendorSelectPlanView()),
            ),
          ],
        ],
      ),
    );
  }

  /// How much of the subscription term is still to run, 0..1.
  ///
  /// Derived from days remaining against the longest term we can infer —
  /// the server sends days_remaining but not the original length, so a
  /// year is assumed. Decorative only; the number beside it is the truth.
  double termRatio(ActiveSubscriptionModel subscription) {
    final days = subscription.daysRemaining ?? 0;

    if (days <= 0) {
      return 0;
    }

    return (days / 365).clamp(0.0, 1.0);
  }

  Widget usagePanel(ActiveSubscriptionModel subscription) {
    final rows = <({IconData icon, String label, QuotaResourceModel? quota})>[
      (
        icon: Icons.folder_outlined,
        label: tr(StringRes.categoriesSection),
        quota: subscription.categories,
      ),
      (
        icon: Icons.list_alt_outlined,
        label: tr(StringRes.subcategoriesCounted),
        quota: subscription.subcategories,
      ),
      (
        icon: Icons.location_on_outlined,
        label: tr(StringRes.zonesSection),
        quota: subscription.zones,
      ),
      (
        icon: Icons.photo_outlined,
        label: tr(StringRes.photosLabel),
        quota: subscription.photos,
      ),
      (
        icon: Icons.videocam_outlined,
        label: tr(StringRes.videosLabel),
        quota: subscription.videos,
      ),
    ];

    return VendorPanel(
      title: tr(StringRes.vendorPlanUsage),
      trailing: BaseTextDMSans(
        text: tr(StringRes.vendorLimitsCount, args: ['${rows.length}']),
        fontSize: 12,
        fontWeight: FontWeight.w600,
        color: ServiceTokens.muted,
      ),
      child: Column(
        children: [
          for (var i = 0; i < rows.length; i++)
            VendorUsageRow(
              icon: rows[i].icon,
              label: rows[i].label,
              used: rows[i].quota?.used ?? 0,
              max: rows[i].quota?.max ?? 0,
              isLast: i == rows.length - 1,
            ),
        ],
      ),
    );
  }

  Widget upsellCard(VendorDashboardController controller, PlanModel upgrade) {
    return Container(
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(color: ServiceTokens.accent.withValues(alpha: 0.35)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                BaseTextDMSans(
                  text: tr(StringRes.vendorNeedMoreRoom),
                  fontSize: 14,
                  fontWeight: FontWeight.w800,
                  color: ServiceTokens.text,
                  textAlign: TextAlign.start,
                ),
                5.heightSpacer,
                BaseTextDMSans(
                  text: tr(StringRes.vendorUpgradePitch, args: [
                    upgrade.name ?? '',
                    '${upgrade.maxCategories ?? 0}',
                    '${upgrade.maxSubcategories ?? 0}',
                    '${upgrade.maxZones ?? 0}',
                  ]),
                  fontSize: 12,
                  color: ServiceTokens.muted,
                  textAlign: TextAlign.start,
                  maxLines: 3,
                ),
              ],
            ),
          ),
          12.widthSpacer,
          _UpgradeButton(
            label: tr(StringRes.vendorUpgrade),
            onTap: () => Get.to(() => const VendorSelectPlanView()),
          ),
        ],
      ),
    );
  }

  // ── No subscription ─────────────────────────────────────────────────────

  /// A vendor normally never reaches this: VendorLandingController routes
  /// them to plan selection before the dashboard is built. It exists for
  /// the case where a subscription lapses while the app is open.
  Widget noActiveSubscriptionState(VendorDashboardController controller) {
    return Center(
      child: Padding(
        padding: EdgeInsets.all(28.getSize),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const VendorIconTile(icon: Icons.workspace_premium_outlined, size: 56),
            18.heightSpacer,
            BaseTextDMSans(
              text: StringRes.noActiveSubscription,
              fontSize: 16,
              fontWeight: FontWeight.w600,
              color: ServiceTokens.text,
              textAlign: TextAlign.center,
            ).tr(),
            20.heightSpacer,
            VendorPrimaryButton(
              label: tr(StringRes.subscribeNow),
              onTap: () => Utils.transitionWithOffAll(const VendorSelectPlanView()),
            ),
            12.heightSpacer,
            TextButton(
              onPressed: controller.logoutAPI,
              child: BaseTextDMSans(
                text: StringRes.signOut,
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.muted,
              ).tr(),
            ),
          ],
        ),
      ),
    );
  }
}

/// The compact outlined "Upgrade" control on the upsell card.
class _UpgradeButton extends StatelessWidget {
  const _UpgradeButton({required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(13.getSize),
        child: Container(
          padding: EdgeInsets.symmetric(
            horizontal: 18.getSize,
            vertical: 12.getSize,
          ),
          decoration: BoxDecoration(
            color: ServiceTokens.accent.withValues(alpha: 0.22),
            borderRadius: BorderRadius.circular(13.getSize),
            border: Border.all(color: ServiceTokens.accent.withValues(alpha: 0.45)),
          ),
          child: BaseTextDMSans(
            text: label,
            fontSize: 14,
            fontWeight: FontWeight.w600,
            color: ServiceTokens.accentBright,
          ),
        ),
      ),
    );
  }
}
