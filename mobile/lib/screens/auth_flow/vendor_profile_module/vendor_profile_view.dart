import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../change_password_module/change_password_view.dart';
import '../delete_account_module/delete_account_view.dart';
import '../vendor_business_details_module/vendor_business_details_view.dart';
import '../vendor_current_plan_module/vendor_current_plan_view.dart';
import '../vendor_login_module/vendor_login_view.dart';
import '../vendor_select_plan_module/vendor_select_plan_view.dart';
import '../../vendor_flow/vendor_documents_module/vendor_documents_view.dart';
import 'vendor_profile_controller.dart';

/// The vendor's account screen (SPEC section 3.2, plus section 2.5's
/// preference pair) — pushed over the dashboard shell from the hero.
class VendorProfileView extends StatelessWidget {
  const VendorProfileView({super.key, this.embedded = false});

  /// True when the screen is a destination on the dashboard's bottom bar
  /// rather than a pushed route.
  ///
  /// Only affects the back chip: embedded there is nothing behind this
  /// screen to pop, and Get.back() would take the whole dashboard with
  /// it.
  final bool embedded;

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorProfileController>(
      init: VendorProfileController(),
      dispose: (_) => Get.delete<VendorProfileController>(),
      builder: (controller) {
        if (controller.isLoading && controller.vendorMe == null) {
          return const Scaffold(
            backgroundColor: ServiceTokens.bg,
            body: Center(
              child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
            ),
          );
        }

        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: ListView(
            padding: EdgeInsets.zero,
            children: [
              hero(controller),
              Transform.translate(
                offset: Offset(0, -34.getSize),
                child: Padding(
                  padding: EdgeInsets.fromLTRB(16.getSize, 0, 16.getSize, 0),
                  child: Column(
                    children: [
                      statStrip(controller),
                      20.heightSpacer,
                      accountGroup(controller),
                      18.heightSpacer,
                      subscriptionGroup(controller),
                      18.heightSpacer,
                      preferencesGroup(controller),
                      20.heightSpacer,
                      logoutButton(controller),
                      12.heightSpacer,
                      deleteButton(),
                      18.heightSpacer,
                      BaseTextDMSans(
                        text: tr(StringRes.vendorAppFooter),
                        fontSize: 12,
                        color: ServiceTokens.muted2,
                        textAlign: TextAlign.center,
                      ),
                      22.heightSpacer,
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

  Widget hero(VendorProfileController controller) {
    final subscription = controller.vendorMe?.activeSubscription;

    return VendorHero(
      bottomPadding: 56.getSize,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              if (!embedded)
                VendorIconButton(
                  icon: Icons.chevron_left,
                  onTap: Get.back,
                  tooltip: tr(StringRes.backToLogin),
                ),
              Expanded(
                child: BaseTextDMSans(
                  text: tr(StringRes.vendorProfileTitle),
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: Colors.white,
                  textAlign: TextAlign.center,
                ),
              ),
              // Balances the back chip so the title sits truly centred.
              // Embedded there is no chip, so no counterweight either.
              if (!embedded) SizedBox(width: 42.getSize),
            ],
          ),
          18.heightSpacer,
          Center(
            child: VendorAvatarTile(
              initials: controller.initials,
              photoUrl: controller.vendorMe?.avatarUrl,
              size: 86,
              fontSize: 18,
            ),
          ),
          14.heightSpacer,
          BaseTextDMSans(
            text: controller.vendorMe?.businessName ?? '',
            fontSize: 18,
            fontWeight: FontWeight.w800,
            color: Colors.white,
            textAlign: TextAlign.center,
            maxLines: 2,
          ),
          if (subscription != null) ...[
            10.heightSpacer,
            Center(child: planPill(subscription)),
          ],
        ],
      ),
    );
  }

  Widget planPill(ActiveSubscriptionModel subscription) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 14.getSize, vertical: 7.getSize),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(22.getSize),
        border: Border.all(color: Colors.white.withValues(alpha: 0.16)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.workspace_premium_outlined,
            size: 15.getSize,
            color: Colors.white,
          ),
          7.widthSpacer,
          Flexible(
            child: BaseTextDMSans(
              text: tr(StringRes.vendorPlanPill, args: [
                subscription.planName ?? '',
                '${subscription.daysRemaining ?? 0}',
              ]),
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: Colors.white,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }

  /// Categories / Subcategories / Zones at a glance.
  Widget statStrip(VendorProfileController controller) {
    final subscription = controller.vendorMe?.activeSubscription;

    if (subscription == null) {
      return const SizedBox.shrink();
    }

    return Container(
      padding: EdgeInsets.symmetric(vertical: 16.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: IntrinsicHeight(
        child: Row(
          children: [
            Expanded(
              child: statCell(
                '${subscription.categories?.used ?? 0}',
                tr(StringRes.categoriesSection),
              ),
            ),
            divider(),
            Expanded(
              child: statCell(
                '${subscription.subcategories?.used ?? 0}',
                tr(StringRes.subcategoriesCounted),
              ),
            ),
            divider(),
            Expanded(
              child: statCell(
                '${subscription.zones?.used ?? 0}',
                // Singular when there is exactly one, which is the common
                // case on the starter plan.
                (subscription.zones?.used ?? 0) == 1
                    ? tr(StringRes.vendorZoneSingular)
                    : tr(StringRes.zonesSection),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget statCell(String value, String label) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        BaseTextDMSans(
          text: value,
          fontSize: 18,
          fontWeight: FontWeight.w800,
          color: ServiceTokens.text,
        ),
        4.heightSpacer,
        BaseTextDMSans(
          text: label,
          fontSize: 12,
          color: ServiceTokens.muted,
          textAlign: TextAlign.center,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
      ],
    );
  }

  Widget divider() => VerticalDivider(
        width: 1.getSize,
        thickness: 1.getSize,
        indent: 6.getSize,
        endIndent: 6.getSize,
        color: ServiceTokens.stroke,
      );

  Widget accountGroup(VendorProfileController controller) {
    final vendor = controller.vendorMe;

    return VendorSettingsGroup(
      title: tr(StringRes.vendorAccountGroup),
      rows: [
        // One row, not two: contact details are part of the business
        // details screen now. A vendor thinks of "how customers find me"
        // as one thing, and splitting six fields across two screens only
        // added a hop.
        VendorSettingsRow(
          first: true,
          icon: Icons.storefront_outlined,
          title: tr(StringRes.vendorBusinessDetails),
          subtitle: vendor?.businessName ?? tr(StringRes.vendorBusinessDetailsSub),
          trailing: const VendorRowChevron(),
          onTap: () async {
            await Get.to(() => const VendorBusinessDetailsView());
            // Reloads so the header and stats reflect an edit made in
            // there — that screen owns its own copy of the record.
            controller.fetchVendorMeAPI();
          },
        ),
        // Documents can expire or be sent back for a re-upload long after
        // approval, so they stay reachable from here.
        VendorSettingsRow(
          icon: Icons.verified_user_outlined,
          title: tr(StringRes.vendorDocumentsTitle),
          subtitle: tr(StringRes.kycDocumentsRowSub),
          trailing: const VendorRowChevron(),
          onTap: () => Get.to(() => const VendorDocumentsView()),
        ),
        VendorSettingsRow(
          icon: Icons.lock_outline,
          title: tr(StringRes.changePasswordRow),
          trailing: const VendorRowChevron(),
          onTap: () => Get.to(() => const ChangePasswordView()),
        ),
      ],
    );
  }

  Widget subscriptionGroup(VendorProfileController controller) {
    final subscription = controller.vendorMe?.activeSubscription;
    final upgrade = controller.upgradePlan;

    if (subscription == null) {
      return const SizedBox.shrink();
    }

    return VendorSettingsGroup(
      title: tr(StringRes.vendorSubscriptionGroup),
      rows: [
        VendorSettingsRow(
          first: true,
          icon: Icons.workspace_premium_outlined,
          title: tr(StringRes.vendorCurrentPlan),
          subtitle: tr(StringRes.vendorCurrentPlanSub, args: [
            subscription.planName ?? '',
            '${subscription.daysRemaining ?? 0}',
          ]),
          trailing: const VendorRowChevron(),
          onTap: () => Get.to(() => const VendorCurrentPlanView()),
        ),
        // Hidden for now (Constants.showVendorUpsell), and omitted on the
        // top tier regardless — an "Upgrade" row that leads to nothing
        // better is worse than no row.
        if (Constants.showVendorUpsell && upgrade != null)
          VendorSettingsRow(
            icon: Icons.arrow_upward,
            title: tr(StringRes.vendorUpgradeTo, args: [upgrade.name ?? '']),
            subtitle: tr(StringRes.vendorUpgradeToSub, args: [
              '${upgrade.maxCategories ?? 0}',
              '${upgrade.maxSubcategories ?? 0}',
              '${upgrade.maxZones ?? 0}',
            ]),
            trailing: const VendorRowChevron(),
            onTap: () => Get.to(() => const VendorSelectPlanView()),
          ),
      ],
    );
  }

  Widget preferencesGroup(VendorProfileController controller) {
    return VendorSettingsGroup(
      title: tr(StringRes.preferencesGroup),
      rows: [
        VendorSettingsRow(
          first: true,
          icon: Icons.notifications_none,
          title: tr(StringRes.notificationsRow),
          trailing: Switch.adaptive(
            value: controller.enableNotification,
            onChanged: controller.isSavingPreference
                ? null
                : controller.setNotifications,
            activeTrackColor: ServiceTokens.accent,
          ),
        ),
        VendorSettingsRow(
          icon: Icons.language,
          title: tr(StringRes.languageRow),
          trailing: VendorRowValue(value: Constants.languages[Injector.language] ?? 'English'),
          onTap: () => showLanguagePicker(
            current: Injector.language,
            onSelected: controller.changeLanguageAPI,
          ),
        ),
        VendorSettingsRow(
          icon: Icons.help_outline,
          title: tr(StringRes.helpSupport),
          trailing: const VendorRowChevron(),
          onTap: () => Get.dialog(
            AlertDialog(
              backgroundColor: ServiceTokens.card,
              title: BaseTextDMSans(
                text: tr(StringRes.helpSupport),
                fontSize: 16,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.text,
              ),
              content: BaseTextDMSans(
                text: tr(StringRes.helpSupportBody),
                fontSize: 14,
                color: ServiceTokens.muted,
              ),
              actions: [
                TextButton(
                  onPressed: Get.back,
                  child: BaseTextDMSans(
                    text: tr(StringRes.cancel),
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: ServiceTokens.accentBright,
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget logoutButton(VendorProfileController controller) {
    return VendorSecondaryButton(
      label: tr(StringRes.logout),
      icon: Icons.logout,
      onTap: controller.logoutAPI,
    );
  }

  /// Destructive, so it is visually separated from sign-out and carries
  /// the panel's only red — the confirmation itself lives on the screen
  /// it opens, which takes a password.
  Widget deleteButton() {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: () => Get.to(
          () => DeleteAccountView(
            loginViewBuilder: () => const VendorLoginView(),
          ),
        ),
        borderRadius: BorderRadius.circular(16.getSize),
        child: Container(
          height: 54.getSize,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: ColorRes.errorColor.withValues(alpha: 0.10),
            borderRadius: BorderRadius.circular(16.getSize),
            border: Border.all(color: ColorRes.errorColor.withValues(alpha: 0.40)),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.delete_outline,
                size: 18.getSize,
                color: ColorRes.errorColor,
              ),
              8.widthSpacer,
              BaseTextDMSans(
                text: tr(StringRes.deleteAccountAction),
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: ColorRes.errorColor,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
