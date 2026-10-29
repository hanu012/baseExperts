import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../customer_register_module/customer_register_view.dart';
import 'customer_login_controller.dart';

/// Customer sign-in (SPEC section 4.1).
class CustomerLoginView extends StatelessWidget {
  const CustomerLoginView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerLoginController>(
      init: CustomerLoginController(),
      dispose: (_) => Get.delete<CustomerLoginController>(),
      builder: (controller) {
        return AuthScaffold(
          title: StringRes.customerLoginTitle,
          subtitle: StringRes.customerLoginDesc,
          formKey: controller.formKey,
          brandIcon: Icons.handyman_outlined,
          brandTitle: StringRes.customerPortal,
          brandSubtitle: StringRes.customerPortalDesc,
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
            onTap: () => Get.off(() => const CustomerRegisterView()),
          ),
        );
      },
    );
  }
}
