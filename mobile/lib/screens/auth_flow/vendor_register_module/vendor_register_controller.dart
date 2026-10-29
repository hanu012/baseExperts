import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/flavor_config.dart';
import '../check_email_module/check_email_view.dart';

/// Vendor self-registration (SPEC section 3.1) — built fresh, not adapted
/// from the ported create_account_module (that screen only ever collects a
/// display name and posts to a different, unrelated endpoint; it is not a
/// real registration flow for any role).
///
/// `role` is not a form field — the vendor app only ever registers
/// vendors, so it is read from FlavorConfig.current.role instead of asked
/// for. business_name/phone are required here because the backend Vendor
/// row created alongside the User row (task 4.1) has NOT NULL columns for
/// both — owner_name is not a separate field, it mirrors `name`, same as
/// the salesman-led draft flow already treats the two as one value.
///
/// No password here: the server emails a "set your password" link, which
/// also proves the address is theirs. Success goes to [CheckEmailView]; the
/// first sign-in comes after the link is used. No token is issued, so
/// nothing is stored.
class VendorRegisterController extends GetxController {
  TextEditingController nameController = TextEditingController();
  TextEditingController emailController = TextEditingController();
  TextEditingController businessNameController = TextEditingController();
  TextEditingController phoneController = TextEditingController();

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
        'business_name': businessNameController.text.trim(),
        'phone': phoneController.text.trim(),
        'role': FlavorConfig.current.role,
      };

      Utils.showCircularProgressLottie(true);
      final response = await DataSource.instance.registerAPI(body: body);
      Utils.showCircularProgressLottie(false);

      if (response == null || !response.isSuccess || response.data == null) {
        // Includes the email not going out (PASSWORD_LINK_NOT_SENT): the
        // server has kept nothing, so the same details can be sent again.
        final fieldError = response?.fieldError('email') ??
            response?.fieldError('phone') ??
            response?.fieldError('business_name');

        Utils.showToast(
          fieldError ?? response?.message ?? tr(StringRes.somethingWentWrong),
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
        print('Vendor register error $e');
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

  String? validatePhone(String? value) {
    final phone = (value ?? '').trim();

    if (phone.isEmpty) {
      return tr(StringRes.enterPhone);
    }

    if (!GetUtils.isPhoneNumber(phone)) {
      return tr(StringRes.invalidPhone);
    }

    return null;
  }

  @override
  void onClose() {
    nameController.dispose();
    emailController.dispose();
    businessNameController.dispose();
    phoneController.dispose();
    super.onClose();
  }
}
