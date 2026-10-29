import 'package:easy_localization/easy_localization.dart';
import 'package:share_plus/share_plus.dart';

import '../../../constants/app.export.dart';

/// Add Vendor, final step — the vendor is now Active. Shows the temp
/// credentials Subscribe just generated (shown once, per SPEC section 2.2)
/// and hands them to the salesman to share.
class SubscriptionConfirmationController extends GetxController {
  final String businessName;
  final String loginEmail;
  final String temporaryPassword;
  final SubscriptionModel subscription;

  /// A renewal: no new login details, just the plan (and a payment link
  /// while Razorpay is on).
  final bool isRenewal;

  /// Firebase sign-in on the server: the vendor was emailed a "set your
  /// password" link instead of getting a temporary password. The masked
  /// address ("r***@gmail.com"); empty when no link went out.
  String passwordLinkSentTo;

  bool isResendingLink = false;

  SubscriptionConfirmationController({
    required this.businessName,
    required this.loginEmail,
    required this.temporaryPassword,
    required this.subscription,
    this.isRenewal = false,
    this.passwordLinkSentTo = '',
  });

  bool get passwordLinkSent => passwordLinkSentTo.isNotEmpty;

  /// A renewal paid in cash has nothing to send the vendor.
  bool get hasSomethingToShare => !isRenewal || paymentLinkUrl.isNotEmpty;

  /// "Resend link" — a new "set your password" email, for when the first
  /// one expired (about an hour) or did not arrive.
  Future<void> resendPasswordLinkAPI() async {
    final vendorId = subscription.vendorId;

    if (vendorId == null || isResendingLink) {
      return;
    }

    try {
      isResendingLink = true;
      update();
      Utils.showCircularProgressLottie(true);
      final response = await DataSource.instance.sendVendorPasswordLinkAPI(vendorId: vendorId);
      Utils.showCircularProgressLottie(false);

      if (response == null || !response.isSuccess) {
        Utils.showToast(response?.message ?? tr(StringRes.passwordLinkResendFailed), isError: true);
        return;
      }

      final data = response.data as Map<String, dynamic>?;
      passwordLinkSentTo = (data?['password_link_sent_to'] as String?) ?? passwordLinkSentTo;
      Utils.showToast(tr(StringRes.passwordLinkResent));
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Resend password link error $e');
      }
      Utils.showToast(tr(StringRes.passwordLinkResendFailed), isError: true);
    } finally {
      isResendingLink = false;
      update();
    }
  }

  /// Plain string interpolation rather than a StringRes template — this is
  /// the message content handed to WhatsApp, not app UI chrome, and
  /// easy_localization named-args aren't used anywhere else in this app.
  String get shareMessage {
    if (isRenewal) {
      return 'Hi $businessName, your ${tr(StringRes.appName)} plan renewal is ready.\n\n'
          'Pay here to activate it: $paymentLinkUrl';
    }

    final login = passwordLinkSent
        ? 'Welcome to ${tr(StringRes.appName)}, $businessName!\n\n'
            'Your vendor account is ready. We have emailed $loginEmail a link to '
            'set your password - open it, choose a password, then log in with that email.'
        : 'Welcome to ${tr(StringRes.appName)}, $businessName!\n\n'
            'Your vendor account is ready. Log in with:\n'
            'Email: $loginEmail\n'
            'Temporary password: $temporaryPassword\n\n'
            'You will be asked to set your own password on first login.';

    if (paymentLinkUrl.isEmpty) {
      return login;
    }

    // Razorpay on: the plan activates once this is paid — the same link
    // the vendor was sent by SMS.
    return '$login\n\nPay here to activate your plan: $paymentLinkUrl';
  }

  /// The sale is waiting on the vendor paying a Razorpay link.
  bool get isAwaitingPayment => subscription.isAwaitingPayment;

  String get paymentLinkUrl => subscription.paymentLinkUrl ?? '';

  Future<void> shareCredentials() async {
    try {
      await Share.share(shareMessage);
    } catch (e) {
      if (kDebugMode) {
        print('Share credentials error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    }
  }

  /// Unwinds the Add Vendor flow back to the home screen it started from.
  ///
  /// Deliberately NOT Get.offAll(SalesmanHomeView()): home is still mounted
  /// at the root of this stack, and replacing it builds a SECOND one while
  /// the first is alive. The new MyVendorsView's GetBuilder then reuses the
  /// already-registered MyVendorsController rather than creating its own,
  /// and tearing the old route down disposes that shared controller —
  /// taking the search field's TextEditingController with it, under a
  /// screen that is now rendering it ("A TextEditingController was used
  /// after being disposed").
  ///
  /// Popping to the root keeps the original home, and its controllers,
  /// exactly as they were. MyVendorsController.resumeDraftAPI and
  /// SalesmanHomeView.addVendor both refresh the list when the flow
  /// returns, so the newly-sold vendor still appears.
  void done() {
    Get.until((route) => route.isFirst);
  }
}
