import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../change_password_module/change_password_view.dart';
import '../delete_account_module/delete_account_view.dart';
import '../salesman_login_module/salesman_login_view.dart';
import 'salesman_profile_controller.dart';

/// The salesman's profile (SPEC section 2.5) — identity, performance and
/// preferences.
///
/// Read-mostly by design. Of everything on a salesman's record, only their
/// photo, name, email and phone are theirs to change (Personal details);
/// the employee code, region, target and commission rate are
/// organisational facts an admin owns, so they render as values rather
/// than fields.
class SalesmanProfileView extends StatelessWidget {
  const SalesmanProfileView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SalesmanProfileController>(
      init: SalesmanProfileController(),
      dispose: (_) => Get.delete<SalesmanProfileController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          body: body(controller),
        );
      },
    );
  }

  Widget body(SalesmanProfileController controller) {
    if (controller.isLoading && controller.profile == null) {
      return const Center(
        child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
      );
    }

    final profile = controller.profile;

    if (profile == null) {
      return errorState(controller);
    }

    return RefreshIndicator(
      onRefresh: controller.fetchProfileAPI,
      color: ServiceTokens.accent,
      backgroundColor: ServiceTokens.card,
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          hero(controller, profile),
          Transform.translate(
            // Lifts the stats card so it straddles the hero's bottom edge,
            // as in the spec. The hero reserves the height below, so
            // nothing after it shifts.
            offset: Offset(0, -40.getSize),
            child: Padding(
              padding: EdgeInsets.fromLTRB(16.getSize, 0, 16.getSize, 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  statsCard(profile),
                  18.heightSpacer,
                  accountGroup(controller, profile),
                  // The whole group is earnings: both of its rows are
                  // hidden, so showing an empty titled section would just
                  // look broken.
                  if (Constants.showSalesmanEarnings) performanceGroup(profile),
                  preferencesGroup(controller, profile),
                  SalesmanWideButton(
                    label: tr(StringRes.logOutAction),
                    icon: Icons.logout,
                    onTap: controller.logoutAPI,
                  ),
                  SalesmanWideButton(
                    label: tr(StringRes.deleteAccountAction),
                    icon: Icons.delete_outline,
                    danger: true,
                    onTap: () => Get.to(
                      () => DeleteAccountView(
                        loginViewBuilder: () => const SalesmanLoginView(),
                      ),
                    ),
                  ),
                  16.heightSpacer,
                  Center(
                    child: BaseTextDMSans(
                      text: '${tr(StringRes.appVersionLabel)} · v1.0.0',
                      fontSize: 10,
                      color: ServiceTokens.muted2,
                    ),
                  ),
                  24.heightSpacer,
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget errorState(SalesmanProfileController controller) {
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
              onPressed: controller.fetchProfileAPI,
              buttonText: StringRes.retry,
              buttonColor: ServiceTokens.accent,
            ),
          ],
        ),
      ),
    );
  }

  // ── Hero ────────────────────────────────────────────────────────────────

  Widget hero(SalesmanProfileController controller, SalesmanProfileModel profile) {
    return SalesmanHero(
      center: true,
      // Extra bottom room for the stats card that overlaps this edge.
      bottomPadding: 60.getSize,
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              HeroIconButton(icon: Icons.chevron_left, onTap: () => Get.back()),
              BaseTextDMSans(
                text: StringRes.profileTitle,
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: Colors.white,
              ).tr(),
              SizedBox(width: 36.getSize),
            ],
          ),
          18.heightSpacer,
          ProfilePhoto(
            initials: profile.initials,
            url: profile.avatarUrl,
            size: 84,
            radius: 26.getSize,
            onTap: controller.changePhoto,
          ),
          12.heightSpacer,
          BaseTextDMSans(
            text: profile.name ?? '',
            fontSize: 18,
            fontWeight: FontWeight.w800,
            color: Colors.white,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          8.heightSpacer,
          Container(
            padding: EdgeInsets.symmetric(horizontal: 12.getSize, vertical: 4.getSize),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.15),
              borderRadius: BorderRadius.circular(20.getSize),
              border: Border.all(color: Colors.white.withValues(alpha: 0.16)),
            ),
            child: BaseTextDMSans(
              text: '${tr(StringRes.fieldSalesman)} · ${profile.employeeCode ?? ''}',
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: const Color(0xFFCCFBF1),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }

  Widget statsCard(SalesmanProfileModel profile) {
    final stats = profile.stats;

    return Container(
      padding: EdgeInsets.symmetric(horizontal: 6.getSize, vertical: 16.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(color: ServiceTokens.stroke),
        boxShadow: ServiceTokens.cardShadow,
      ),
      child: IntrinsicHeight(
        child: Row(
          children: [
            Expanded(
              child: statCell('${stats?.totalVendors ?? 0}', tr(StringRes.totalVendors)),
            ),
            divider(),
            Expanded(
              child: statCell(
                '${stats?.subscribedVendors ?? 0}',
                tr(StringRes.subscribedLabel),
              ),
            ),
            if (Constants.showSalesmanEarnings) ...[
              divider(),
              Expanded(
                child: statCell(
                  Utils.compactRupees(stats?.earningsPaise ?? 0),
                  tr(StringRes.earningsLabel),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget divider() => VerticalDivider(
        width: 1.getSize,
        thickness: 1.getSize,
        indent: 6.getSize,
        endIndent: 6.getSize,
        color: ServiceTokens.stroke,
      );

  Widget statCell(String value, String label) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        BaseTextDMSans(
          text: value,
          fontSize: 18,
          fontWeight: FontWeight.w800,
          color: ServiceTokens.text,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        3.heightSpacer,
        BaseTextDMSans(
          text: label,
          fontSize: 10,
          fontWeight: FontWeight.w500,
          color: ServiceTokens.muted,
          textAlign: TextAlign.center,
          maxLines: 2,
        ),
      ],
    );
  }

  // ── Groups ──────────────────────────────────────────────────────────────

  Widget accountGroup(SalesmanProfileController controller, SalesmanProfileModel profile) {
    return SettingsGroup(
      title: tr(StringRes.accountGroup),
      rows: [
        SettingsRow(
          first: true,
          icon: Icons.person_outline,
          title: tr(StringRes.personalDetails),
          subtitle: tr(StringRes.personalDetailsSub),
          trailing: const RowChevron(),
          onTap: () => controller.openPersonalDetails(
            footer: SalesmanReadOnlyDetails(profile: profile),
          ),
        ),
        SettingsRow(
          icon: Icons.apartment_outlined,
          title: tr(StringRes.assignedRegion),
          // Set by an admin, so this is a value, not an editable field.
          subtitle: profile.region ?? tr(StringRes.notSetLabel),
          trailing: const SizedBox.shrink(),
        ),
        SettingsRow(
          icon: Icons.lock_outline,
          title: tr(StringRes.changePasswordRow),
          trailing: const RowChevron(),
          onTap: () => Get.to(() => const ChangePasswordView()),
        ),
      ],
    );
  }

  Widget performanceGroup(SalesmanProfileModel profile) {
    final stats = profile.stats;
    final target = stats?.target;

    return SettingsGroup(
      title: tr(StringRes.performanceGroup),
      rows: [
        SettingsRow(
          first: true,
          icon: Icons.payments_outlined,
          title: tr(StringRes.earningsPayouts),
          subtitle: '${tr(StringRes.thisMonthLabel)} '
              '${Utils.rupees(stats?.earningsPaise ?? 0)}',
          trailing: const SizedBox.shrink(),
        ),
        // Only shown when a target is actually set: "no target" and "0% of
        // a target" are different states, and an empty bar reads as failure.
        if (target != null && target.hasTarget)
          SettingsRow(
            icon: Icons.track_changes_outlined,
            title: tr(StringRes.targetsRow),
            subtitle: '${Utils.rupees(target.achievedPaise)} '
                '${tr(StringRes.of)} ${Utils.rupees(target.monthlyTargetPaise)}',
            trailing: RowValue(value: '${target.percent}%'),
          ),
      ],
    );
  }

  Widget preferencesGroup(
    SalesmanProfileController controller,
    SalesmanProfileModel profile,
  ) {
    return SettingsGroup(
      title: tr(StringRes.preferencesGroup),
      rows: [
        SettingsRow(
          first: true,
          icon: Icons.notifications_none,
          title: tr(StringRes.notificationsRow),
          trailing: SalesmanSwitch(
            value: profile.enableNotification,
            onChanged: controller.setNotifications,
          ),
        ),
        SettingsRow(
          icon: Icons.language,
          title: tr(StringRes.languageRow),
          trailing: RowValue(value: Constants.languages[Injector.language] ?? 'English'),
          onTap: () => showLanguagePicker(
            current: Injector.language,
            onSelected: controller.changeLanguageAPI,
          ),
        ),
        SettingsRow(
          icon: Icons.help_outline,
          title: tr(StringRes.helpSupport),
          trailing: const RowChevron(),
          onTap: () => Get.dialog(
            AlertDialog(
              backgroundColor: ServiceTokens.card,
              title: BaseTextDMSans(
                text: StringRes.helpSupport,
                fontSize: 16,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.text,
              ).tr(),
              content: BaseTextDMSans(
                text: StringRes.helpSupportBody,
                fontSize: 12,
                color: ServiceTokens.muted,
                textAlign: TextAlign.start,
              ).tr(),
              actions: [
                TextButton(
                  onPressed: () => Get.back(),
                  child: BaseTextDMSans(
                    text: StringRes.cancel,
                    color: ServiceTokens.accentBright,
                  ).tr(),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

/// The admin-owned part of a salesman's record, shown under the editable
/// personal details as values rather than fields.
class SalesmanReadOnlyDetails extends StatelessWidget {
  const SalesmanReadOnlyDetails({super.key, required this.profile});

  final SalesmanProfileModel? profile;

  @override
  Widget build(BuildContext context) {
    final profile = this.profile;

    if (profile == null) {
      return const SizedBox.shrink();
    }

    return SalesmanPanel(
      title: tr(StringRes.accountGroup),
      child: Column(
        children: [
          detailRow(tr(StringRes.employeeCodeLabel), profile.employeeCode),
          detailRow(
            tr(StringRes.assignedRegion),
            profile.region ?? tr(StringRes.notSetLabel),
          ),
          if (Constants.showSalesmanEarnings)
            detailRow(
              tr(StringRes.commissionRateLabel),
              // Server-formatted: deriving this in the app is how a
              // salesman ends up seeing a rate that disagrees with their
              // actual payout.
              '${profile.commissionRatePercent ?? '0.00'}%',
            ),
        ],
      ),
    );
  }

  Widget detailRow(String label, String? value) {
    return Padding(
      padding: EdgeInsets.only(bottom: 12.getSize),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            flex: 2,
            child: BaseTextDMSans(
              text: label,
              fontSize: 12,
              color: ServiceTokens.muted,
              textAlign: TextAlign.start,
            ),
          ),
          Expanded(
            flex: 3,
            child: BaseTextDMSans(
              text: value ?? '—',
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: ServiceTokens.text,
              textAlign: TextAlign.end,
            ),
          ),
        ],
      ),
    );
  }
}
