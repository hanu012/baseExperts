import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../../vendor_flow/add_vendor_module/add_vendor_view.dart';
import '../earnings_module/earnings_view.dart';
import '../my_vendors_module/my_vendors_controller.dart';
import '../my_vendors_module/my_vendors_view.dart';
import '../salesman_profile_module/salesman_profile_view.dart';
import 'salesman_home_controller.dart';

/// Salesman home: a branded hero with headline stats, then the My Vendors
/// and Earnings tabs (SPEC sections 2.3-2.5).
///
/// Stays a const no-arg widget: every existing call site (login,
/// change-password, subscription confirmation's "Done", and app.dart's
/// already-logged-in check) constructs it with no arguments.
///
/// Sign out and account deletion moved to the profile screen, which is
/// where a user looks for them — the hero's two buttons are now Add vendor
/// and Profile.
class SalesmanHomeView extends StatelessWidget {
  const SalesmanHomeView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SalesmanHomeController>(
      init: SalesmanHomeController(),
      dispose: (_) => Get.delete<SalesmanHomeController>(),
      builder: (controller) {
        // With earnings hidden there is one tab left. The TabBar is kept
        // rather than dropped so the screen does not visibly restructure
        // when the flag goes back on, and so "My Vendors" still reads as a
        // heading for the list below it.
        const showEarnings = Constants.showSalesmanEarnings;

        return DefaultTabController(
          length: showEarnings ? 2 : 1,
          child: Scaffold(
            backgroundColor: ServiceTokens.bg,
            body: Column(
              children: [
                hero(controller),
                tabs(),
                Expanded(
                  child: TabBarView(
                    children: [
                      const MyVendorsView(),
                      if (showEarnings) const EarningsView(),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget hero(SalesmanHomeController controller) {
    final stats = controller.profile?.stats;

    return SalesmanHero(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  Container(
                    height: 9.getSize,
                    width: 9.getSize,
                    decoration: BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                      boxShadow: [
                        BoxShadow(
                          color: Colors.white.withValues(alpha: 0.22),
                          blurRadius: 0,
                          spreadRadius: 4.getSize,
                        ),
                      ],
                    ),
                  ),
                  12.widthSpacer,
                  BaseTextDMSans(
                    text: StringRes.salesmanHomeTitle,
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: Colors.white,
                  ).tr(),
                ],
              ),
              Row(
                children: [
                  HeroIconButton(
                    icon: Icons.add,
                    tooltip: tr(StringRes.addVendorTitle),
                    onTap: addVendor,
                  ),
                  9.widthSpacer,
                  HeroIconButton(
                    icon: Icons.person_outline,
                    tooltip: tr(StringRes.profileTitle),
                    onTap: () async {
                      await Get.to(() => const SalesmanProfileView());
                      // The profile screen can change the name, so pull the
                      // greeting and stats again on the way back.
                      controller.fetchProfileAPI();
                    },
                  ),
                ],
              ),
            ],
          ),
          16.heightSpacer,
          RichText(
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            text: TextSpan(
              children: [
                TextSpan(
                  text: '${tr(StringRes.welcomeBack)} ',
                  style: TextStyle(
                    fontFamily: FontFamily.poppins,
                    fontSize: 12.getFontSize,
                    fontWeight: FontWeight.w500,
                    color: Colors.white.withValues(alpha: 0.82),
                  ),
                ),
                TextSpan(
                  text: controller.greetingName,
                  style: TextStyle(
                    fontFamily: FontFamily.poppins,
                    fontSize: 12.getFontSize,
                    fontWeight: FontWeight.w600,
                    color: Colors.white,
                  ),
                ),
              ],
            ),
          ),
          3.heightSpacer,
          BaseTextDMSans(
            text: StringRes.yourVendors,
            fontSize: 18,
            fontWeight: FontWeight.w800,
            color: Colors.white,
            textAlign: TextAlign.start,
          ).tr(),
          16.heightSpacer,
          HeroStatRow(
            tiles: [
              HeroStatTile(
                value: '${stats?.totalVendors ?? 0}',
                label: tr(StringRes.totalVendors),
              ),
              HeroStatTile(
                value: '${stats?.subscribedVendors ?? 0}',
                label: tr(StringRes.subscribedLabel),
              ),
              if (Constants.showSalesmanEarnings)
                HeroStatTile(
                  value: Utils.compactRupees(stats?.earningsPaise ?? 0),
                  label: tr(StringRes.earningsLabel),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget tabs() {
    return Container(
      color: ServiceTokens.bg,
      padding: EdgeInsets.symmetric(horizontal: 6.getSize),
      child: TabBar(
        isScrollable: true,
        tabAlignment: TabAlignment.start,
        labelColor: ServiceTokens.accent,
        unselectedLabelColor: ServiceTokens.navInactive,
        indicatorColor: ServiceTokens.accent,
        indicatorWeight: 3,
        indicatorSize: TabBarIndicatorSize.label,
        dividerColor: Colors.transparent,
        labelStyle: TextStyle(
          fontFamily: FontFamily.poppins,
          fontSize: 14.getFontSize,
          fontWeight: FontWeight.w600,
        ),
        unselectedLabelStyle: TextStyle(
          fontFamily: FontFamily.poppins,
          fontSize: 14.getFontSize,
          fontWeight: FontWeight.w600,
        ),
        tabs: [
          Tab(text: tr(StringRes.myVendorsTab)),
          if (Constants.showSalesmanEarnings) Tab(text: tr(StringRes.earningsTab)),
        ],
      ),
    );
  }

  /// My Vendors' own controller stays alive for the duration of this
  /// tabbed screen (TabBarView keeps both tabs built), so refreshing it on
  /// return shows the just-added vendor immediately instead of waiting for
  /// the next pull-to-refresh.
  Future<void> addVendor() async {
    await Get.to(() => const AddVendorView());

    if (Get.isRegistered<MyVendorsController>()) {
      Get.find<MyVendorsController>().fetchVendorsAPI();
    }
    if (Get.isRegistered<SalesmanHomeController>()) {
      Get.find<SalesmanHomeController>().fetchProfileAPI();
    }
  }
}
