import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// "Forgot password?" for all three flavours.
///
/// An email with no account is refused by the server and the reason shown
/// under the field.
///
/// The only way a forgotten password is reset — admins cannot. The server
/// emails a link (sent by Laravel or Firebase, the admin's choice; the app
/// does not need to know which). It opens a web page where the user sets a
/// new password, then they sign in here as usual.
///
/// Opened with Get.to over the login screen, so finishing is Get.back(): the
/// login screen is still mounted underneath.
class ForgotPasswordController extends GetxController {
  ForgotPasswordController({String initialEmail = ''}) {
    emailController.text = initialEmail;
    emailController.addListener(_clearServerError);
  }

  TextEditingController emailController = TextEditingController();

  GlobalKey<FormState> formKey = GlobalKey<FormState>();
  AutovalidateMode autoValidateMode = AutovalidateMode.disabled;

  /// The server's answer about the email ("No account found with this
  /// email"), shown under the field until the address is edited.
  String? emailServerError;
  String _emailAtError = '';

  void _clearServerError() {
    if (emailServerError != null && emailController.text != _emailAtError) {
      emailServerError = null;
      update();
    }
  }

  Future<void> sendResetRequestAPI() async {
    emailServerError = null;

    if (!(formKey.currentState?.validate() ?? false)) {
      autoValidateMode = AutovalidateMode.onUserInteraction;
      update();
      return;
    }

    try {
      final body = {'email': emailController.text.trim()};

      Utils.showCircularProgressLottie(true);
      CommonResponse? commonResponse =
          await DataSource.instance.passwordResetRequestAPI(body: body);
      Utils.showCircularProgressLottie(false);

      if (commonResponse == null) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      if (!commonResponse.isSuccess) {
        // An email with no account comes back as a field error: shown
        // under the field, where the typo is.
        final emailError = commonResponse.fieldError('email');

        if (emailError != null) {
          emailServerError = emailError;
          _emailAtError = emailController.text;
          autoValidateMode = AutovalidateMode.onUserInteraction;
          formKey.currentState?.validate();
          update();
          return;
        }

        // Rate limit or anything else from the server.
        Utils.showToast(
          commonResponse.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      Utils.showToast(tr(StringRes.resetLinkSent));
      Get.back();
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Password reset request error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    }
  }

  String? validateEmail(String? value) {
    final email = (value ?? '').trim();

    if (email.isEmpty) {
      return tr(StringRes.enterYourEmail);
    }

    if (!GetUtils.isEmail(email)) {
      return tr(StringRes.invalidEmail);
    }

    return emailServerError;
  }

  @override
  void onClose() {
    emailController.removeListener(_clearServerError);
    emailController.dispose();
    super.onClose();
  }
}
