import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'check_email_controller.dart';

/// "Check your email" after self-registration; see [CheckEmailController].
class CheckEmailView extends StatelessWidget {
  const CheckEmailView({
    super.key,
    required this.email,
    required this.maskedEmail,
  });

  final String email;
  final String maskedEmail;

  @override
  Widget build(BuildContext context) {
    return GetBuilder<CheckEmailController>(
      init: CheckEmailController(email: email, maskedEmail: maskedEmail),
      dispose: (_) => Get.delete<CheckEmailController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ColorRes.backgroundColor,
          body: ListView(
            padding: EdgeInsets.zero,
            children: [
              AuthGradientHero(
                icon: Icons.mark_email_read_outlined,
                title: tr(StringRes.checkEmailTitle),
                subtitle: tr(
                  StringRes.checkEmailDesc,
                  args: [controller.maskedEmail],
                ),
              ),
              Padding(
                padding: EdgeInsets.fromLTRB(
                  24.getSize,
                  24.getSize,
                  24.getSize,
                  30.getSize,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    BaseTextDMSans(
                      text: StringRes.checkEmailSteps,
                      fontSize: 14,
                      fontWeight: FontWeight.w400,
                      color: ColorRes.secondaryColor,
                      textAlign: TextAlign.start,
                      lineHeight: 1.45,
                      maxLines: 4,
                    ).tr(),
                    20.heightSpacer,
                    const AuthTipBox(
                      message: StringRes.checkEmailSpamTip,
                      icon: Icons.info_outline,
                    ),
                    28.heightSpacer,
                    AuthPrimaryButton(
                      label: StringRes.goToSignIn,
                      onPressed: controller.goToSignIn,
                      trailingIcon: Icons.arrow_forward,
                    ),
                    12.heightSpacer,
                    Center(
                      child: AuthTextLink(
                        labelKey: StringRes.resendLink,
                        onTap: controller.resendLinkAPI,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}
