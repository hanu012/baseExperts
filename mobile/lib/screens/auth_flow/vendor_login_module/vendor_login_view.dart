import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../vendor_register_module/vendor_register_view.dart';
import 'vendor_login_controller.dart';

/// Vendor sign-in (SPEC section 3.1). Same shape as SalesmanLoginView, plus
/// a link to registration — vendors can self-register, salesmen cannot.
class VendorLoginView extends StatelessWidget {
  const VendorLoginView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorLoginController>(
      init: VendorLoginController(),
      dispose: (_) => Get.delete<VendorLoginController>(),
      builder: (controller) {
        return AuthScaffold(
          title: StringRes.vendorLoginTitle,
          subtitle: StringRes.vendorLoginDesc,
          formKey: controller.formKey,
          brandIcon: Icons.storefront_outlined,
          brandTitle: StringRes.vendorPortal,
          brandSubtitle: StringRes.vendorPortalDesc,
          showBack: false,
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
          footer: AuthFooterLink(
            promptKey: StringRes.dontHaveAccount,
            actionKey: StringRes.registerButton,
            onTap: () => Get.off(() => const VendorRegisterView()),
          ),
        );
      },
    );
  }
}
