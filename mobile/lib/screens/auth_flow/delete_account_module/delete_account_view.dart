import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'delete_account_controller.dart';

/// Self-service account deletion (SPEC section 4 item 10). A dedicated
/// confirmation screen rather than a dialog, given how destructive this
/// is — reachable from each flavor's home app bar, sharing this one
/// module (see DeleteAccountController's own docblock on
/// [loginViewBuilder]).
class DeleteAccountView extends StatelessWidget {
  final Widget Function() loginViewBuilder;

  const DeleteAccountView({super.key, required this.loginViewBuilder});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<DeleteAccountController>(
      init: DeleteAccountController(loginViewBuilder: loginViewBuilder),
      dispose: (_) => Get.delete<DeleteAccountController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ColorRes.backgroundColor,
          appBar: AppBar(
            title: BaseTextDMSans(
              text: StringRes.deleteAccountTitle,
              fontSize: 18,
              fontWeight: FontWeight.w600,
              color: ColorRes.secondaryColor,
            ).tr(),
          ),
          body: body(controller),
          bottomNavigationBar: bottomButton(controller, context),
        );
      },
    );
  }

  Widget body(DeleteAccountController controller) {
    return SingleChildScrollView(
      padding: EdgeInsets.all(16.getSize),
      child: Form(
        key: controller.formKey,
        autovalidateMode: controller.autoValidateMode,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            warningBanner(),
            24.heightSpacer,
            BaseTextDMSans(
              text: StringRes.password,
              fontWeight: FontWeight.w500,
              fontSize: 14,
              color: ColorRes.secondaryColor,
            ).tr(),
            8.heightSpacer,
            BaseTextField(
              controller: controller.passwordController,
              hintText: tr(StringRes.deleteAccountPasswordHint),
              isShowBorder: true,
              isSecure: controller.obscurePassword,
              validator: controller.validatePassword,
              textInputAction: TextInputAction.done,
              onFieldSubmitted: (_) => controller.deleteAccountAPI(),
              suffixIcon: IconButton(
                onPressed: controller.togglePasswordVisibility,
                icon: Icon(
                  controller.obscurePassword
                      ? Icons.visibility_off_outlined
                      : Icons.visibility_outlined,
                  color: ColorRes.grayColor,
                  size: 20.getSize,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget warningBanner() {
    return Container(
      padding: EdgeInsets.all(14.getSize),
      decoration: BoxDecoration(
        color: ColorRes.errorColor.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(12.getSize),
        border: Border.all(color: ColorRes.errorColor),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.warning_amber_rounded, color: ColorRes.errorColor, size: 22.getSize),
          10.widthSpacer,
          Expanded(
            child: BaseTextDMSans(
              text: StringRes.deleteAccountWarning,
              fontSize: 12,
              color: ColorRes.secondaryColor,
            ).tr(),
          ),
        ],
      ),
    );
  }

  Widget bottomButton(DeleteAccountController controller, BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 16.getSize,
        right: 16.getSize,
        top: 10.getSize,
        bottom: MediaQuery.of(context).viewInsets.bottom + 16.getSize,
      ),
      child: BaseRaisedButton(
        onPressed: controller.isSubmitting ? null : controller.deleteAccountAPI,
        buttonText: StringRes.deleteAccountConfirmButton,
        buttonColor: ColorRes.errorColor,
      ),
    );
  }
}
