import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'change_password_controller.dart';

/// Changing your own password (SPEC section 2.1).
///
/// Moved off [Utils.authLayout] onto the auth design system the sign-in
/// screens already use — this is an auth screen in everything but name,
/// and it was the last one still carrying the old chrome.
class ChangePasswordView extends StatelessWidget {
  const ChangePasswordView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<ChangePasswordController>(
      init: ChangePasswordController(),
      dispose: (_) => Get.delete<ChangePasswordController>(),
      builder: (controller) {
        // Trapped only for the FORCED change: there is nowhere to go back
        // to, and the server blocks every other endpoint until it is done,
        // so an escape hatch would lead to an app that looks signed in and
        // fails on its first real request. A voluntary change opened from
        // the profile screen has a real stack behind it and must stay
        // backable — trapping that one strands the user.
        return PopScope(
          canPop: !controller.isForced,
          child: Scaffold(
            backgroundColor: ColorRes.backgroundColor,
            resizeToAvoidBottomInset: true,
            // The whole page scrolls, CTA included, rather than pinning it
            // to a bottomNavigationBar: a pinned bar has to dodge the
            // keyboard by hand and still ends up sitting over the field it
            // belongs to. base_auth.dart documents the same choice for the
            // sign-in pair.
            body: ListView(
              padding: EdgeInsets.zero,
              children: [
                AuthGradientHero(
                  icon: Icons.lock_outline,
                  title: tr(StringRes.changePasswordTitle),
                  subtitle: tr(StringRes.changePasswordDesc),
                ),
                Padding(
                  padding: EdgeInsets.fromLTRB(
                    24.getSize,
                    24.getSize,
                    24.getSize,
                    30.getSize,
                  ),
                  child: form(controller),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget form(ChangePasswordController controller) {
    return Form(
      key: controller.formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          fieldLabel(StringRes.currentPassword),
          8.heightSpacer,
          BaseTextField(
            controller: controller.currentPasswordController,
            hintText: tr(StringRes.enterCurrentPassword),
            isShowBorder: true,
            isSecure: controller.obscureCurrent,
            validateMode: controller.autoValidateMode,
            textInputAction: TextInputAction.next,
            validator: controller.validateCurrent,
            suffixIcon: AuthVisibilityToggle(
              isObscured: controller.obscureCurrent,
              onPressed: controller.toggleCurrentVisibility,
            ),
          ),
          20.heightSpacer,

          fieldLabel(StringRes.newPassword),
          8.heightSpacer,
          BaseTextField(
            controller: controller.newPasswordController,
            hintText: tr(StringRes.enterNewPassword),
            isShowBorder: true,
            isSecure: controller.obscureNew,
            validateMode: controller.autoValidateMode,
            textInputAction: TextInputAction.next,
            validator: controller.validateNew,
            onChanged: controller.onPasswordChanged,
            suffixIcon: AuthVisibilityToggle(
              isObscured: controller.obscureNew,
              onPressed: controller.toggleNewVisibility,
            ),
          ),
          20.heightSpacer,

          fieldLabel(StringRes.confirmNewPassword),
          8.heightSpacer,
          BaseTextField(
            controller: controller.confirmPasswordController,
            hintText: tr(StringRes.confirmNewPassword),
            isShowBorder: true,
            isSecure: controller.obscureNew,
            validateMode: controller.autoValidateMode,
            textInputAction: TextInputAction.done,
            validator: controller.validateConfirm,
            onChanged: controller.onPasswordChanged,
            onFieldSubmitted: (_) => controller.changePasswordAPI(),
          ),
          22.heightSpacer,

          // Ticks move as the user types, so the rule still failing is
          // visible before submitting rather than after a rejection.
          AuthChecklistCard(
            title: tr(StringRes.passwordShouldHave),
            items: {
              tr(StringRes.passwordRuleLength): controller.hasMinimumLength,
              tr(StringRes.passwordRuleMatch): controller.passwordsMatch,
            },
          ),
          28.heightSpacer,

          // First login on a temporary password is setting one, not
          // resetting it.
          AuthPrimaryButton(
            label: controller.isForced
                ? StringRes.setPassword
                : StringRes.resetPassword,
            onPressed: controller.changePasswordAPI,
            trailingIcon: Icons.arrow_forward,
          ),
        ],
      ),
    );
  }

  Widget fieldLabel(String key) {
    return BaseTextDMSans(
      text: key,
      fontWeight: FontWeight.w600,
      fontSize: 14,
      color: ColorRes.secondaryColor,
      textAlign: TextAlign.start,
    ).tr();
  }
}
