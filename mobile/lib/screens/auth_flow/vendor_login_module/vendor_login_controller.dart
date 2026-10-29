import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../account_verification_module/account_verification_view.dart';
import '../change_password_module/change_password_view.dart';
import '../forgot_password_module/forgot_password_view.dart';
import '../vendor_landing_module/vendor_landing_view.dart';

/// Vendor sign-in (SPEC section 3.1) — same shape as
/// SalesmanLoginController, but vendors can self-register (see
/// VendorRegisterController).
///
/// Sign-in itself is never refused for want of approval: the server issues
/// a token regardless and the gate lives on the other endpoints, so an
/// unapproved vendor lands on the account-verification screen with a
/// working session rather than being bounced back to this form with a
/// toast and no way forward.
class VendorLoginController extends GetxController {
  TextEditingController emailController = TextEditingController();
  TextEditingController passwordController = TextEditingController();

  GlobalKey<FormState> formKey = GlobalKey<FormState>();
  AutovalidateMode autoValidateMode = AutovalidateMode.disabled;

  bool obscurePassword = true;

  /// Remembers the EMAIL only, never the password — same rule as the
  /// salesman app. Survives logout (Injector.clearUserData leaves it).
  bool rememberMe = false;

  @override
  void onInit() {
    super.onInit();

    final saved = Injector.prefs?.getString(PrefKeys.rememberedEmail) ?? '';

    if (saved.isNotEmpty) {
      emailController.text = saved;
      rememberMe = true;
    }
  }

  void toggleRememberMe(bool value) {
    rememberMe = value;
    update();
  }

  /// Opens "Forgot password?", which files a reset request for an admin —
  /// the platform sends no mail, so there is no emailed link. Pushed with
  /// Get.to (not offAll) so the user returns to this screen afterwards.
  void openForgotPassword() {
    Get.to(() => ForgotPasswordView(initialEmail: emailController.text.trim()));
  }

  void togglePasswordVisibility() {
    obscurePassword = !obscurePassword;
    update();
  }

  Future<void> loginAPI() async {
    if (!(formKey.currentState?.validate() ?? false)) {
      autoValidateMode = AutovalidateMode.onUserInteraction;
      update();
      return;
    }

    try {
      final email = emailController.text.trim();
      final body = {
        'email': email,
        'password': passwordController.text,
        'device_name': await Injector.deviceName(),
      };

      Utils.showCircularProgressLottie(true);
      CommonResponse? commonResponse = await DataSource.instance.loginAPI(body: body);
      Utils.showCircularProgressLottie(false);

      if (commonResponse == null) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      if (!commonResponse.isSuccess || commonResponse.data == null) {
        Utils.showToast(
          commonResponse.message ?? tr(StringRes.invalidCredentials),
          isError: true,
        );
        return;
      }

      UserModel userModel = UserModel.fromJson(commonResponse.data);

      // The server's auth/login is shared across all three flavours and
      // does not reject by role (it can't know which app is calling) — so
      // a customer or salesman entering their own credentials here would
      // otherwise sign in fine and only fail later, confusingly, on
      // vendor-only endpoints. Reject it here instead.
      if (userModel.role != 'vendor') {
        if (userModel.authentication?.accessToken != null) {
          await Injector.setAccessToken(userModel.authentication!.accessToken!);
          await DataSource.instance.logoutAPI();
        }
        await Injector.clearUserData();
        Utils.showToast(tr(StringRes.wrongAppForAccount), isError: true);
        return;
      }

      await Injector.setUserData(userModel);

      // Persisted only on success: remembering an address that just failed
      // to sign in would prefill a typo forever.
      if (rememberMe) {
        await Injector.prefs?.setString(
          PrefKeys.rememberedEmail,
          emailController.text.trim(),
        );
      } else {
        await Injector.prefs?.remove(PrefKeys.rememberedEmail);
      }

      if (userModel.mustChangePassword) {
        Utils.transitionWithOffAll(const ChangePasswordView());
        return;
      }

      // Checked after the forced password change, matching the server:
      // RequirePasswordChange runs before RequireApprovedAccount, so a user
      // carrying both would be sent to a pending screen whose every button
      // the password gate would then refuse.
      if (!userModel.isApproved) {
        Utils.transitionWithOffAll(const AccountVerificationView());
        return;
      }

      // SPEC section 3.2: has_active_subscription decides dashboard vs
      // plan-selection — VendorLandingController makes that check.
      Utils.transitionWithOffAll(const VendorLandingView());
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Vendor login error $e');
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

    return null;
  }

  String? validatePassword(String? value) {
    if ((value ?? '').isEmpty) {
      return tr(StringRes.enterYourPassword);
    }

    return null;
  }

  @override
  void onClose() {
    emailController.dispose();
    passwordController.dispose();
    super.onClose();
  }
}
