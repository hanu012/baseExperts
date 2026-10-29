import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../account_verification_module/account_verification_view.dart';
import '../change_password_module/change_password_view.dart';
import '../forgot_password_module/forgot_password_view.dart';
import '../salesman_home_module/salesman_home_view.dart';

/// Salesman sign-in (SPEC section 2.1) — login only, no registration.
/// Salesmen never self-register; an admin creates the account and hands over
/// a temporary password (SPEC section 1).
///
/// Follows the demo-app pattern from task 0.5a: plain non-reactive fields
/// mutated directly then update(), one method per screen action, the API call
/// wrapped in try/catch with the progress overlay toggled around it.
class SalesmanLoginController extends GetxController {
  TextEditingController emailController = TextEditingController();
  TextEditingController passwordController = TextEditingController();

  GlobalKey<FormState> formKey = GlobalKey<FormState>();
  AutovalidateMode autoValidateMode = AutovalidateMode.disabled;

  bool obscurePassword = true;

  /// Remembers the EMAIL only, never the password. This app stores no
  /// credentials, and a temp-password flow makes that especially unwise —
  /// the convenience being bought is not retyping an address.
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

  void togglePasswordVisibility() {
    obscurePassword = !obscurePassword;
    update();
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

  Future<void> loginAPI() async {
    if (!(formKey.currentState?.validate() ?? false)) {
      autoValidateMode = AutovalidateMode.onUserInteraction;
      update();
      return;
    }

    try {
      final body = {
        'email': emailController.text.trim(),
        'password': passwordController.text,
        // One personal access token per device (CLAUDE.md). Re-signing in on
        // the same device replaces that device's token rather than stacking a
        // new one each time.
        'device_name': await Injector.deviceName(),
      };

      Utils.showCircularProgressLottie(true);
      CommonResponse? commonResponse =
          await DataSource.instance.loginAPI(body: body);
      Utils.showCircularProgressLottie(false);

      if (commonResponse == null) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      if (!commonResponse.isSuccess || commonResponse.data == null) {
        // The server distinguishes bad credentials from an unverified email,
        // so show its message rather than a generic one.
        Utils.showToast(
          commonResponse.message ?? tr(StringRes.invalidCredentials),
          isError: true,
        );
        return;
      }

      UserModel userModel = UserModel.fromJson(commonResponse.data);

      // The server's auth/login is shared across all three flavours and
      // does not reject by role (it can't know which app is calling) — so
      // a vendor or customer entering their own credentials here would
      // otherwise sign in fine and only fail later, confusingly, on
      // salesman-only endpoints. Reject it here instead.
      if (userModel.role != 'salesman') {
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

      // SPEC section 2.1: forced password change on first login. The server
      // enforces this too — every other endpoint returns
      // PASSWORD_CHANGE_REQUIRED until it is done — so this is a redirect for
      // the user's sake, not the security boundary.
      if (userModel.mustChangePassword) {
        Utils.transitionWithOffAll(const ChangePasswordView());
        return;
      }

      // A salesman is admin-created and so approved from the start, but an
      // admin can revoke that later from the Users list — in which case
      // this is the screen they get rather than a home screen whose every
      // request the server would refuse.
      if (!userModel.isApproved) {
        Utils.transitionWithOffAll(const AccountVerificationView());
        return;
      }

      Utils.transitionWithOffAll(const SalesmanHomeView());
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Salesman login error $e');
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
