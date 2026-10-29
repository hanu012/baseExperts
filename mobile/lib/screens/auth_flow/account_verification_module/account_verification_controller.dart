import 'dart:async';

import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/flavor_config.dart';
import '../../../utils/socket_service.dart';
import '../customer_home_module/customer_home_view.dart';
import '../customer_login_module/customer_login_view.dart';
import '../salesman_home_module/salesman_home_view.dart';
import '../salesman_login_module/salesman_login_view.dart';
import '../vendor_landing_module/vendor_landing_view.dart';
import '../vendor_login_module/vendor_login_view.dart';
import '../../vendor_flow/vendor_documents_module/vendor_documents_view.dart';
import '../../vendor_flow/vendor_kyc_stepper_module/vendor_kyc_stepper_view.dart';

/// "Your account is in verification" — the only screen an unapproved
/// account can reach, in all three flavours (SPEC section 3.1).
///
/// Admin approval replaced email verification as the single gate, so this
/// is where every self-registration lands and where any sign-in lands while
/// the account is still pending or has been rejected. Home is never built
/// underneath: the API refuses every endpoint it would call, so a home
/// screen here would be a wall of failed requests.
///
/// Two actions only, matching what the server still allows an unapproved
/// token to do: re-check the decision, and sign out — plus, in the vendor
/// app, uploading KYC documents (`vendors/me/documents` is reachable while
/// pending), since admin cannot approve a vendor until every compulsory
/// document is verified.
///
/// The decision also arrives by itself: the server pushes
/// `account.approval` over the live socket the moment an admin approves or
/// rejects (SocketService), and this screen re-checks when the app comes
/// back to the foreground in case that push was missed while it was away.
/// Either way it is the same quiet re-check, so the button is a fallback.
class AccountVerificationController extends GetxController {
  bool isChecking = false;

  static const String _listenerId = 'AccountVerificationController';
  StreamSubscription<bool>? _resumeSubscription;

  /// Backstop for a socket that is not connected (live events off, Reverb
  /// down, or unreachable from the phone): without it the screen only
  /// moved on when the app was backgrounded and reopened.
  Timer? _pollTimer;
  static const Duration pollInterval = Duration(seconds: 15);

  @override
  void onInit() {
    super.onInit();

    final socket = SocketService.instance;
    socket.on('account.approval', _listenerId, (_) => checkStatusAPI(quiet: true));
    // A document's status changed (verified, re-upload asked, ...): only a
    // hint, so the documents are re-read rather than patched from it.
    socket.on('kyc.document', _listenerId, (_) => fetchDocumentsAPI());
    _resumeSubscription = socket.appResumedStream.listen((_) {
      checkStatusAPI(quiet: true);
      fetchDocumentsAPI();
    });
    socket.connect();

    // Only polls while the socket is down — a live socket already delivers
    // the decision the moment it is made.
    _pollTimer = Timer.periodic(pollInterval, (_) {
      if (!SocketService.instance.isConnected && !isRejected) {
        checkStatusAPI(quiet: true);
      }
    });

    fetchDocumentsAPI();
  }

  /// The vendor's own KYC documents, for the "still missing" summary. Null
  /// until read, and always null outside the vendor app.
  VendorDocumentsModel? documents;

  bool get showDocuments => FlavorConfig.current.flavor == Flavor.vendor && !isRejected;

  /// Quiet by design — the summary is a hint on a screen whose job is
  /// something else, so a failure just leaves it out.
  Future<void> fetchDocumentsAPI() async {
    if (!showDocuments) {
      return;
    }

    try {
      final response = await DataSource.instance.vendorDocumentsAPI();

      if (response != null && response.isSuccess && response.data is Map<String, dynamic>) {
        documents = VendorDocumentsModel.fromJson(response.data as Map<String, dynamic>);
        update();
        _autoOpenStepper();
      }
    } catch (e) {
      if (kDebugMode) {
        print('Account verification documents error $e');
      }
    }
  }

  /// The plain documents list ("View documents", once nothing compulsory is
  /// left to upload). Pushed, not offAll: this screen stays underneath and
  /// re-reads the summary on the way back.
  Future<void> openDocuments() async {
    await Get.to(() => const VendorDocumentsView());
    await fetchDocumentsAPI();
  }

  /// Compulsory documents the vendor can still upload (pending upload,
  /// re-upload, expired) — the screen then leads with "Upload documents"
  /// (the stepper) rather than "View documents" (the list).
  bool get hasDocumentsToUpload => documents?.compulsoryToUpload.isNotEmpty ?? false;

  /// Set the first time the stepper opens on this screen, by itself or by
  /// the button, so the automatic open happens at most once per screen: a
  /// vendor who backs out of it is not pushed straight back in by the
  /// re-read that follows (or by a resume or a later poll).
  bool stepperOpened = false;

  /// Opens the KYC stepper by itself when the vendor lands here with
  /// compulsory documents still to upload — the thing that actually moves
  /// their approval along.
  void _autoOpenStepper() {
    if (stepperOpened || isClosed || !showDocuments || !hasDocumentsToUpload) {
      return;
    }

    unawaited(openStepper());
  }

  /// Pushed, not offAll: this screen stays underneath (the stepper comes
  /// back to it on submit) and re-reads the documents on the way back.
  Future<void> openStepper() async {
    stepperOpened = true;
    await Get.to(() => const VendorKycStepperView());
    await fetchDocumentsAPI();
  }

  @override
  void onClose() {
    SocketService.instance.off('account.approval', _listenerId);
    SocketService.instance.off('kyc.document', _listenerId);
    _resumeSubscription?.cancel();
    _pollTimer?.cancel();
    super.onClose();
  }

  /// Mirrors Injector so the view can redraw after a check without the
  /// whole screen depending on static state.
  String status = Injector.userData?.approvalStatus ?? 'pending';
  String? note = Injector.userData?.approvalNote;

  bool get isRejected => status == 'rejected';

  /// Re-reads the profile to see whether an admin has decided yet.
  ///
  /// [quiet] for the automatic checks (socket push, app resume): no
  /// "still pending" toast and no error toast for something the user did
  /// not ask for. Approval and rejection are always announced.
  ///
  /// GET /user is deliberately one of the few routes left open to an
  /// unapproved token — without it this screen would have no way out except
  /// signing out and back in.
  Future<void> checkStatusAPI({bool quiet = false}) async {
    if (isChecking) {
      return;
    }

    isChecking = true;
    update();

    try {
      final response = await DataSource.instance.userAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        if (!quiet) {
          Utils.showToast(
            response?.message ?? tr(StringRes.somethingWentWrong),
            isError: true,
          );
        }
        return;
      }

      final userModel = UserModel.fromJson(response.data as Map<String, dynamic>);

      // isFromEditProfile: this response carries no token, and letting it
      // through would blank the one we are signed in with.
      await Injector.setUserData(userModel, isFromEditProfile: true);

      final wasRejected = isRejected;
      status = userModel.approvalStatus ?? 'pending';
      note = userModel.approvalNote;

      if (userModel.isApproved) {
        Utils.showToast(tr(StringRes.accountApproved));
        Utils.transitionWithOffAll(_homeForFlavor());
        return;
      }

      // Quiet checks only speak up for news: a rejection that just landed.
      if (!quiet || (isRejected && !wasRejected)) {
        Utils.showToast(
          isRejected ? tr(StringRes.accountRejectedTitle) : tr(StringRes.accountStillPending),
          isError: isRejected,
        );
      }
    } catch (e) {
      if (kDebugMode) {
        print('Account verification check error $e');
      }
      if (!quiet) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
      }
    } finally {
      isChecking = false;
      update();
    }
  }

  /// The one way off this screen that always works. Mirrors the profile
  /// screens' sign-out: the local session is cleared even if the server
  /// call fails, because leaving someone stuck here on a bad connection is
  /// the worse outcome.
  Future<void> logoutAPI() async {
    try {
      Utils.showCircularProgressLottie(true);
      await DataSource.instance.logoutAPI();
      Utils.showCircularProgressLottie(false);
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Account verification logout error $e');
      }
    }

    await Injector.clearUserData();

    Utils.showToast(tr(StringRes.logout));
    Utils.transitionWithOffAll(_loginForFlavor());
  }

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

  Widget _loginForFlavor() {
    switch (FlavorConfig.current.flavor) {
      case Flavor.salesman:
        return const SalesmanLoginView();
      case Flavor.vendor:
        return const VendorLoginView();
      case Flavor.customer:
        return const CustomerLoginView();
    }
  }
}
