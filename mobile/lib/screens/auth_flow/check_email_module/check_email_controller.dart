import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/flavor_config.dart';
import '../customer_login_module/customer_login_view.dart';
import '../vendor_login_module/vendor_login_view.dart';

/// After self-registration (vendor and customer apps). Sign-up takes no
/// password: the server emails a "set your password" link instead, and the
/// person signs in here once they have used it. Nobody is signed in on this
/// screen.
///
/// Reached with transitionWithOffAll from the register screen, so it is the
/// whole stack — leaving for the login screen is offAll too (login is not
/// mounted underneath).
class CheckEmailController extends GetxController {
  CheckEmailController({required this.email, required this.maskedEmail});

  /// Full address, for "Resend link".
  final String email;

  /// As the server returned it ("r***@gmail.com"), for display.
  final String maskedEmail;

  /// "Resend link" is the same request as "Forgot password?" — the account
  /// exists and has not chosen a password, so its page says "Set your
  /// password" either way.
  Future<void> resendLinkAPI() async {
    try {
      Utils.showCircularProgressLottie(true);
      CommonResponse? commonResponse =
          await DataSource.instance.passwordResetRequestAPI(body: {'email': email});
      Utils.showCircularProgressLottie(false);

      if (commonResponse == null || !commonResponse.isSuccess) {
        // Rate limit or anything else from the server.
        Utils.showToast(
          commonResponse?.fieldError('email') ??
              commonResponse?.message ??
              tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      Utils.showToast(tr(StringRes.passwordLinkResent));
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Resend password link error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    }
  }

  void goToSignIn() {
    Utils.transitionWithOffAll(
      FlavorConfig.current.flavor == Flavor.vendor
          ? const VendorLoginView()
          : const CustomerLoginView(),
    );
  }
}
