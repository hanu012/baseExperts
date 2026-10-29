import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// Self-service account deletion (SPEC section 4 item 10, "required for
/// app store compliance") — reuses the backend's existing
/// User::deleteWithTombstone() mechanism, calling the shared
/// DataSource.deleteAccountAPI(). Same password-confirmation-before-a-
/// destructive-action shape as changing a password: a stolen or
/// borrowed device with a live session should not be enough on its own
/// to delete the real owner's account.
///
/// [loginViewBuilder] is the flavor's own login screen — vendor,
/// salesman and customer each navigate here differently after logout,
/// and this screen is shared across all three rather than forked per
/// flavor.
class DeleteAccountController extends GetxController {
  final Widget Function() loginViewBuilder;

  DeleteAccountController({required this.loginViewBuilder});

  /// Swappable seam for tests — Utils.transitionWithOffAll() drives a
  /// real Navigator, same reasoning as [VendorDetailController]'s
  /// launchUrlFn/shareFn seams.
  @visibleForTesting
  void Function(Widget page) navigateFn = Utils.transitionWithOffAll;

  GlobalKey<FormState> formKey = GlobalKey<FormState>();
  TextEditingController passwordController = TextEditingController();

  AutovalidateMode autoValidateMode = AutovalidateMode.disabled;
  bool obscurePassword = true;
  bool isSubmitting = false;

  void togglePasswordVisibility() {
    obscurePassword = !obscurePassword;
    update();
  }

  String? validatePassword(String? value) {
    if ((value ?? '').isEmpty) {
      return tr(StringRes.enterYourPassword);
    }

    return null;
  }

  Future<void> deleteAccountAPI() async {
    if (!(formKey.currentState?.validate() ?? false)) {
      autoValidateMode = AutovalidateMode.onUserInteraction;
      update();
      return;
    }

    if (isSubmitting) {
      return;
    }

    isSubmitting = true;
    update();

    try {
      final response = await DataSource.instance.deleteAccountAPI(
        password: passwordController.text,
      );

      if (response == null || !response.isSuccess) {
        Utils.showToast(response?.message ?? tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      await Injector.clearUserData();
      Utils.showToast(tr(StringRes.deleteAccountSucceeded));
      navigateFn(loginViewBuilder());
    } catch (e) {
      if (kDebugMode) {
        print('Delete account error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isSubmitting = false;
      update();
    }
  }

  @override
  void onClose() {
    passwordController.dispose();
    super.onClose();
  }
}
