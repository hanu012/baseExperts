import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_select_plan_controller.dart';

/// Vendor self-service, step 1 — plan selection (SPEC section 3.2).
///
/// The first thing a vendor sees when they sign in without a live
/// subscription, so it is a full-bleed screen of its own rather than an
/// empty state inside the dashboard: there is nothing else for them to do
/// until a plan is bought.
///
/// Sign out is the only other way off it, which is why the hero carries a
/// pill for it where other screens carry a back chip — there is no screen
/// behind this one.
class VendorSelectPlanView extends StatelessWidget {
  const VendorSelectPlanView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorSelectPlanController>(
      init: VendorSelectPlanController(),
      dispose: (_) => Get.delete<VendorSelectPlanController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          bottomNavigationBar: footer(controller, context),
          // The hero scrolls with the plans rather than being pinned above
          // an Expanded — on a short viewport a pinned hero plus the footer
          // can exceed the height and collapse the body to nothing, which
          // reads as "the API returned no plans". base_services.dart
          // documents the same decision for the services screens.
          body: ListView(
            padding: EdgeInsets.zero,
            children: [
              hero(controller),
              Padding(
                padding: EdgeInsets.fromLTRB(
                  16.getSize,
                  18.getSize,
                  16.getSize,
                  24.getSize,
                ),
                child: body(controller),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget hero(VendorSelectPlanController controller) {
    return VendorHero(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Align(
            alignment: Alignment.centerRight,
            child: VendorPillButton(
              label: tr(StringRes.signOut),
              icon: Icons.logout,
              onTap: controller.logoutAPI,
            ),
          ),
          20.heightSpacer,
          VendorStepLabel(text: tr(StringRes.vendorSelectPlanStep)),
          8.heightSpacer,
          VendorHeroTitle(
            title: tr(StringRes.selectPlanTitle),
            subtitle: tr(StringRes.vendorSelectPlanDesc),
          ),
        ],
      ),
    );
  }

  Widget body(VendorSelectPlanController controller) {
    if (controller.isLoading && controller.plans.isEmpty) {
      return Padding(
        padding: EdgeInsets.symmetric(vertical: 60.getSize),
        child: const Center(
          child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
        ),
      );
    }

    if (controller.plans.isEmpty) {
      return Padding(
        padding: EdgeInsets.symmetric(vertical: 50.getSize),
        child: Center(
          child: BaseTextDMSans(
            text: StringRes.somethingWentWrong,
            fontSize: 14,
            color: ServiceTokens.muted,
            textAlign: TextAlign.center,
          ).tr(),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final plan in controller.plans) ...[
          planCard(controller, plan),
          14.heightSpacer,
        ],
      ],
    );
  }

  Widget planCard(VendorSelectPlanController controller, PlanModel plan) {
    final selected = controller.selectedPlanId == plan.id;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: plan.id == null ? null : () => controller.selectPlan(plan.id!),
        borderRadius: BorderRadius.circular(18.getSize),
        child: Container(
          padding: EdgeInsets.all(16.getSize),
          decoration: BoxDecoration(
            color: ServiceTokens.card,
            borderRadius: BorderRadius.circular(18.getSize),
            border: Border.all(
              color: selected ? ServiceTokens.accentBright : ServiceTokens.stroke,
              width: selected ? 1.6 : 1,
            ),
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
                      text: plan.name ?? '',
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  _SelectionDot(selected: selected),
                ],
              ),
              14.heightSpacer,
              priceLine(plan),
              14.heightSpacer,
              Divider(height: 1.getSize, color: ServiceTokens.stroke),
              14.heightSpacer,
              featureGrid(plan),
            ],
          ),
        ),
      ),
    );
  }

  /// Big rupee figure with the term beside it, baseline-aligned.
  Widget priceLine(PlanModel plan) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Flexible(
          child: BaseTextDMSans(
            // From paise, not the server's pre-formatted price_rupees
            // string: that one always carries ".00", and the card wants
            // the headline figure clean.
            text: Utils.rupeesWhole(plan.pricePaise ?? 0),
            fontSize: 18,
            fontWeight: FontWeight.w800,
            color: ServiceTokens.text,
            textAlign: TextAlign.start,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ),
        8.widthSpacer,
        Padding(
          padding: EdgeInsets.only(bottom: 4.getSize),
          child: BaseTextDMSans(
            text: tr(
              StringRes.planForDays,
              args: ['${plan.durationDays ?? 0}'],
            ),
            fontSize: 14,
            fontWeight: FontWeight.w600,
            color: ServiceTokens.muted,
          ),
        ),
      ],
    );
  }

  /// The five allowances, two to a row. Laid out as a wrap of half-width
  /// cells rather than a GridView so the card keeps its intrinsic height
  /// inside a scrolling column.
  Widget featureGrid(PlanModel plan) {
    final features = <String>[
      tr(StringRes.planCategories, args: ['${plan.maxCategories ?? 0}']),
      tr(StringRes.planSubcategories, args: ['${plan.maxSubcategories ?? 0}']),
      tr(StringRes.planZones, args: ['${plan.maxZones ?? 0}']),
      tr(StringRes.planPhotos, args: ['${plan.maxPhotos ?? 0}']),
      tr(StringRes.planVideos, args: ['${plan.maxVideos ?? 0}']),
    ];

    return LayoutBuilder(
      builder: (context, constraints) {
        final columnWidth = (constraints.maxWidth - 12.getSize) / 2;

        return Wrap(
          spacing: 12.getSize,
          runSpacing: 11.getSize,
          children: [
            for (final feature in features)
              SizedBox(width: columnWidth, child: _FeatureLine(label: feature)),
          ],
        );
      },
    );
  }

  Widget footer(VendorSelectPlanController controller, BuildContext context) {
    final planName = controller.selectedPlan?.name;

    return Container(
      color: ServiceTokens.bg,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: EdgeInsets.fromLTRB(
            16.getSize,
            10.getSize,
            16.getSize,
            10.getSize,
          ),
          child: VendorPrimaryButton(
            // Naming the plan on the button is the last confirmation
            // before money changes hands.
            label: planName == null
                ? tr(StringRes.continueLabel)
                : tr(StringRes.continueWithPlan, args: [planName]),
            icon: Icons.arrow_forward,
            onTap: controller.continueToServices,
          ),
        ),
      ),
    );
  }
}

/// The filled check / empty ring on the right of a plan card.
class _SelectionDot extends StatelessWidget {
  const _SelectionDot({required this.selected});

  final bool selected;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 26.getSize,
      width: 26.getSize,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: selected ? ServiceTokens.accentBright : Colors.transparent,
        border: Border.all(
          color: selected ? ServiceTokens.accentBright : ServiceTokens.stroke2,
          width: 1.6,
        ),
      ),
      child: selected
          ? Icon(Icons.check, size: 15.getSize, color: Colors.white)
          : null,
    );
  }
}

/// A green tick and its label — one allowance in the plan card's grid.
class _FeatureLine extends StatelessWidget {
  const _FeatureLine({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Padding(
          padding: EdgeInsets.only(top: 2.getSize),
          child: Icon(Icons.check, size: 15.getSize, color: ServiceTokens.green),
        ),
        7.widthSpacer,
        Expanded(
          child: BaseTextDMSans(
            text: label,
            fontSize: 14,
            fontWeight: FontWeight.w500,
            color: ServiceTokens.text,
            textAlign: TextAlign.start,
            maxLines: 2,
          ),
        ),
      ],
    );
  }
}
