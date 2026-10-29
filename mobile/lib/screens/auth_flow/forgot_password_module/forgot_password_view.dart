import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import 'forgot_password_controller.dart';

/// Shared by all three flavours; see [ForgotPasswordController].
class ForgotPasswordView extends StatelessWidget {
  const ForgotPasswordView({super.key, this.initialEmail = ''});

  /// Whatever was already typed on the login screen, so it is not retyped.
  final String initialEmail;

  @override
  Widget build(BuildContext context) {
    return GetBuilder<ForgotPasswordController>(
      init: ForgotPasswordController(initialEmail: initialEmail),
      dispose: (_) => Get.delete<ForgotPasswordController>(),
      builder: (controller) {
        return AuthScaffold(
          title: StringRes.forgotPasswordTitle,
          subtitle: StringRes.forgotPasswordDescEmail,
          formKey: controller.formKey,
          // Pushed over the login screen, so the default Get.back() works.
          showBack: true,
          fields: [
            AuthTextField(
              label: StringRes.email,
              hint: tr(StringRes.enterYourEmail),
              controller: controller.emailController,
              icon: Icons.mail_outline_rounded,
              validateMode: controller.autoValidateMode,
              textInputType: TextInputType.emailAddress,
              textInputAction: TextInputAction.done,
              validator: controller.validateEmail,
              onFieldSubmitted: (_) => controller.sendResetRequestAPI(),
              isLast: true,
            ),
          ],
          primaryAction: AuthPrimaryButton(
            label: StringRes.sendResetLink,
            onPressed: controller.sendResetRequestAPI,
          ),
        );
      },
    );
  }
}
