import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/flavor_config.dart';
import '../check_email_module/check_email_view.dart';

/// Customer self-registration (SPEC section 4.1) — same shape as
/// VendorRegisterController, trimmed to what RegisterRequest actually
/// needs for role=customer: name and email only, no business_name/phone
/// (vendor-only requirements).
///
/// No password here: the server emails a "set your password" link, which
/// also proves the address is theirs. Success goes to [CheckEmailView]; the
/// first sign-in comes after the link is used, and lands on the
/// account-verification screen until an admin approves (SPEC section 3.1).
class CustomerRegisterController extends GetxController {
  TextEditingController nameController = TextEditingController();
  TextEditingController emailController = TextEditingController();

  GlobalKey<FormState> formKey = GlobalKey<FormState>();
  AutovalidateMode autoValidateMode = AutovalidateMode.disabled;

  Future<void> registerAPI() async {
    if (!(formKey.currentState?.validate() ?? false)) {
      autoValidateMode = AutovalidateMode.onUserInteraction;
      update();
      return;
    }

    try {
      final email = emailController.text.trim();
      final body = {
        'name': nameController.text.trim(),
        'email': email,
        'role': FlavorConfig.current.role,
      };

      Utils.showCircularProgressLottie(true);
      final response = await DataSource.instance.registerAPI(body: body);
      Utils.showCircularProgressLottie(false);

      if (response == null || !response.isSuccess || response.data == null) {
        // Includes the email not going out (PASSWORD_LINK_NOT_SENT): the
        // server has kept nothing, so the same details can be sent again.
        Utils.showToast(
          response?.fieldError('email') ??
              response?.message ??
              tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      final data = response.data as Map<String, dynamic>;

      Utils.transitionWithOffAll(CheckEmailView(
        email: email,
        maskedEmail: (data['password_link_sent_to'] as String?) ?? email,
      ));
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Customer register error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    }
  }

  String? validateRequired(String? value, String messageKey) {
    if ((value ?? '').trim().isEmpty) {
      return tr(messageKey);
    }

    return null;
  }

  String? validateEmail(String? value) {
    final email = (value ?? '').trim();

    if (email.isEmpty) {
      return tr(StringRes.enterYourEmail);
    }

    if (!GetUtils.isEmail(email)) {
      return tr(StringRes.invalidEmail);
    }

    return null;
  }

  @override
  void onClose() {
    nameController.dispose();
    emailController.dispose();
    super.onClose();
  }
}
