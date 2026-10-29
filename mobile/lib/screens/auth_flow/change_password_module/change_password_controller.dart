import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/flavor_config.dart';
import '../account_verification_module/account_verification_view.dart';
import '../customer_home_module/customer_home_view.dart';
import '../salesman_home_module/salesman_home_view.dart';
import '../vendor_landing_module/vendor_landing_view.dart';

/// Changing your own password. Reached two ways, and the difference
/// decides how the screen is left:
///
///  * FORCED — first login with an admin-issued temporary password (SPEC
///    section 2.1). The screen is the whole stack, there is nowhere to go
///    back to, and finishing replaces it with the flavour's home.
///  * VOLUNTARY — opened from the profile screen over a live stack. The
///    home screen is still mounted underneath, so finishing POPS back to
///    it. It must not replace the stack: Get.offAll would build a *second*
///    home while the first is still alive, the new one's GetBuilder would
///    reuse the already-registered controllers rather than making its own,
///    and the old route's dispose would then Get.delete those same
///    controllers out from under it — disposing the search field's
///    TextEditingController while the new screen is rendering it.
///
/// For the forced case the ban on escaping is not just UI politeness — the
/// server returns PASSWORD_CHANGE_REQUIRED for every other endpoint until
/// the change is done, so skipping would produce an app that appears to
/// work and fails on the first real request.
class ChangePasswordController extends GetxController {
  TextEditingController currentPasswordController = TextEditingController();
  TextEditingController newPasswordController = TextEditingController();
  TextEditingController confirmPasswordController = TextEditingController();

  GlobalKey<FormState> formKey = GlobalKey<FormState>();
  AutovalidateMode autoValidateMode = AutovalidateMode.disabled;

  bool obscureCurrent = true;
  bool obscureNew = true;

  /// Captured at entry, because the successful response clears the flag —
  /// reading it after the call would always say "voluntary".
  bool isForced = false;

  @override
  void onInit() {
    super.onInit();
    isForced = Injector.userData?.mustChangePassword ?? false;
  }

  void toggleCurrentVisibility() {
    obscureCurrent = !obscureCurrent;
    update();
  }

  void toggleNewVisibility() {
    obscureNew = !obscureNew;
    update();
  }

  /// Live state for the requirements checklist, so the rule still failing
  /// is visible while typing rather than only after a rejected submit.
  ///
  /// Mirrors validateNew/validateConfirm below; both read the same two
  /// facts, so they cannot disagree about what "valid" means.
  bool get hasMinimumLength => newPasswordController.text.length >= 8;

  bool get passwordsMatch =>
      newPasswordController.text.isNotEmpty &&
      newPasswordController.text == confirmPasswordController.text;

  /// Redraws the checklist as the user types.
  void onPasswordChanged(String _) => update();

  Future<void> changePasswordAPI() async {
    if (!(formKey.currentState?.validate() ?? false)) {
      autoValidateMode = AutovalidateMode.onUserInteraction;
      update();
      return;
    }

    try {
      final body = {
        'current_password': currentPasswordController.text,
        'password': newPasswordController.text,
        'password_confirmation': confirmPasswordController.text,
      };

      Utils.showCircularProgressLottie(true);
      CommonResponse? commonResponse =
          await DataSource.instance.changePasswordAPI(body: body);
      Utils.showCircularProgressLottie(false);

      if (commonResponse == null) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      if (!commonResponse.isSuccess) {
        // The server separates a wrong current password from reusing the same
        // one; both are actionable, so show its message rather than a generic
        // failure.
        Utils.showToast(
          commonResponse.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      // The response carries the updated user with the flag cleared. Storing
      // it keeps the local copy honest — otherwise a later screen reading
      // Injector.userData would still think a change is pending.
      if (commonResponse.data != null) {
        UserModel userModel = UserModel.fromJson(commonResponse.data);
        await Injector.setUserData(userModel, isFromEditProfile: true);
      }

      Utils.showToast(tr(StringRes.passwordChanged));

      // Pop rather than replace when the home screen is already mounted
      // underneath — see the class doc for what rebuilding it costs.
      if (!isForced) {
        Get.back();
        return;
      }

      // Same gate order as app.dart's _gatedOr and the server: the password
      // change came first, and an unapproved account goes to the
      // verification screen next, not to a home whose every call would be
      // refused with ACCOUNT_PENDING_APPROVAL. Reachable when an admin
      // resets the password of a pending or rejected account.
      if (!(Injector.userData?.isApproved ?? false)) {
        Utils.transitionWithOffAll(const AccountVerificationView());
        return;
      }

      Utils.transitionWithOffAll(_homeForFlavor());
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Change password error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    }
  }

  String? validateCurrent(String? value) {
    if ((value ?? '').isEmpty) {
      return tr(StringRes.enterCurrentPassword);
    }

    return null;
  }

  String? validateNew(String? value) {
    final password = value ?? '';

    if (password.isEmpty) {
      return tr(StringRes.enterNewPassword);
    }

    // Mirrors Laravel's Password::defaults() minimum, so the obvious failure
    // is caught here rather than costing a round trip.
    if (password.length < 8) {
      return tr(StringRes.passwordTooShort);
    }

    return null;
  }

  String? validateConfirm(String? value) {
    if (value != newPasswordController.text) {
      return tr(StringRes.passwordsDoNotMatch);
    }

    return null;
  }

  /// This screen is shared by all three apps, so it cannot hardcode one
  /// home — a vendor finishing a forced change used to be dropped on the
  /// salesman home screen.
  Widget _homeForFlavor() {
    switch (FlavorConfig.current.flavor) {
      case Flavor.salesman:
        return const SalesmanHomeView();
      case Flavor.vendor:
        return const VendorLandingView();
      case Flavor.customer:
        return const CustomerHomeView();
    }
  }

  @override
  void onClose() {
    currentPasswordController.dispose();
    newPasswordController.dispose();
    confirmPasswordController.dispose();
    super.onClose();
  }
}
