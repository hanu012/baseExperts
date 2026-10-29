import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../customer_login_module/customer_login_view.dart';
import 'customer_register_controller.dart';

/// Customer self-registration (SPEC section 4.1).
class CustomerRegisterView extends StatelessWidget {
  const CustomerRegisterView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CustomerRegisterController>(
      init: CustomerRegisterController(),
      dispose: (_) => Get.delete<CustomerRegisterController>(),
      builder: (controller) {
        return AuthScaffold(
          title: StringRes.customerRegisterTitle,
          subtitle: StringRes.customerRegisterDesc,
          formKey: controller.formKey,
          showBack: true,
          // Login was replaced (Get.off), not pushed under this screen,
          // so back swaps to it the same way the footer link does.
          onBack: () => Get.off(() => const CustomerLoginView()),
          fields: [
            AuthTextField(
              label: StringRes.fullName,
              hint: tr(StringRes.enterYourFullName),
              controller: controller.nameController,
              icon: Icons.person_outline_rounded,
              validateMode: controller.autoValidateMode,
              textCapitalization: TextCapitalization.words,
              textInputAction: TextInputAction.next,
              validator: (value) =>
                  controller.validateRequired(value, StringRes.enterYourFullName),
            ),
            AuthTextField(
              label: StringRes.email,
              hint: tr(StringRes.enterYourEmail),
              controller: controller.emailController,
              icon: Icons.mail_outline_rounded,
              validateMode: controller.autoValidateMode,
              textInputType: TextInputType.emailAddress,
              textInputAction: TextInputAction.done,
              validator: controller.validateEmail,
              // No password here — it is set from the emailed link.
              onFieldSubmitted: (_) => controller.registerAPI(),
              isLast: true,
            ),
          ],
          primaryAction: AuthPrimaryButton(
            label: StringRes.registerButton,
            onPressed: controller.registerAPI,
          ),
          footer: AuthFooterLink(
            promptKey: StringRes.alreadyHaveAccount,
            actionKey: StringRes.signIn,
            onTap: () => Get.off(() => const CustomerLoginView()),
          ),
        );
      },
    );
  }
}
