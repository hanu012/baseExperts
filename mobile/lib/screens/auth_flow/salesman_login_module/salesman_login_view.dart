import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import 'salesman_login_controller.dart';

/// Salesman sign-in. Login only — no registration link anywhere, because
/// salesmen never self-register (SPEC section 1).
///
/// Same shape as the ported create_account_module: StatelessWidget, build()
/// returns a GetBuilder with init + dispose, state read off the builder
/// argument named `controller` (never `_`, which Dart 3.7+ treats as a
/// non-binding wildcard).
///
/// Chrome comes from [AuthScaffold] — the shared auth design system in
/// widgets/base_auth.dart — so this screen, both vendor screens and both
/// customer screens stay visually identical by construction.
class SalesmanLoginView extends StatelessWidget {
  const SalesmanLoginView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SalesmanLoginController>(
      init: SalesmanLoginController(),
      dispose: (_) => Get.delete<SalesmanLoginController>(),
      builder: (controller) {
        return AuthScaffold(
          title: StringRes.salesmanLoginTitle,
          subtitle: StringRes.salesmanLoginDesc,
          formKey: controller.formKey,
          // No back chip: this is the first screen of the flavour, there is
          // nothing behind it to return to.
          showBack: false,
          brandIcon: Icons.explore_outlined,
          brandTitle: StringRes.salesmanPortal,
          brandSubtitle: StringRes.salesmanPortalDesc,
          extras: AuthExtrasRow(
            checkboxLabel: StringRes.rememberMe,
            checked: controller.rememberMe,
            onCheckedChanged: controller.toggleRememberMe,
          ),
          // Last on the screen, with the other account links — not
          // squeezed onto the Remember me line.
          bottomLink: AuthTextLink(
            labelKey: StringRes.forgotPassword,
            onTap: controller.openForgotPassword,
          ),
          // Salesmen arrive with an admin-issued temporary password and are
          // forced to change it, so say so before they wonder why.
          tip: const AuthTipBox(message: StringRes.firstLoginTip),
          fields: [
            AuthTextField(
              label: StringRes.email,
              hint: tr(StringRes.enterYourEmail),
              controller: controller.emailController,
              icon: Icons.mail_outline_rounded,
              validateMode: controller.autoValidateMode,
              textInputType: TextInputType.emailAddress,
              textInputAction: TextInputAction.next,
              validator: controller.validateEmail,
            ),
            AuthTextField(
              label: StringRes.password,
              hint: tr(StringRes.enterYourPassword),
              controller: controller.passwordController,
              icon: Icons.lock_outline_rounded,
              isSecure: controller.obscurePassword,
              validateMode: controller.autoValidateMode,
              textInputAction: TextInputAction.done,
              validator: controller.validatePassword,
              onFieldSubmitted: (_) => controller.loginAPI(),
              isLast: true,
              suffixIcon: AuthVisibilityToggle(
                isObscured: controller.obscurePassword,
                onPressed: controller.togglePasswordVisibility,
              ),
            ),
          ],
          primaryAction: AuthPrimaryButton(
            label: StringRes.signIn,
            onPressed: controller.loginAPI,
          ),
        );
      },
    );
  }
}
