import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../utils/socket_service.dart';
import '../../auth_flow/account_verification_module/account_verification_controller.dart';
import '../../auth_flow/account_verification_module/account_verification_view.dart';
import '../vendor_document_upload_module/vendor_document_upload_controller.dart';
import '../vendor_document_upload_module/vendor_document_upload_view.dart';

/// "Document verification" — the KYC documents one step at a time.
///
/// Steps are every compulsory document still waiting on an upload (pending
/// upload, sent back for a re-upload, expired), in server order, then one
/// final "Review & submit" step listing every document. Already uploaded or
/// verified ones are not asked for again; with nothing compulsory left the
/// screen is just the review step.
///
/// Each document step is driven by its own [VendorDocumentUploadController]
/// — the same validation and upload as the single-document upload screen —
/// registered under a per-step tag for the life of this screen, so going
/// back a step keeps what was picked. "Save & continue" uploads that one
/// document and moves on; a 422 keeps the step open with its field errors.
///
/// Shared by two apps:
///  * the vendor's own documents ([vendorId] null), opened from the
///    pending-verification screen, which it returns to on submit;
///  * the salesman's Add Vendor ([vendorId] set, [allowSkip] true), which
///    gets `true` back on submit and refreshes its progress line.
class VendorKycStepperController extends GetxController {
  final int? vendorId;
  final bool allowSkip;

  VendorKycStepperController({this.vendorId, this.allowSkip = false});

  VendorDocumentsModel? documents;
  bool isLoading = false;
  bool hasError = false;

  /// The compulsory documents to upload, fixed once read: uploading one
  /// changes its status, but it stays a step so Back still reaches it.
  List<VendorDocumentModel> steps = [];
  bool _stepsBuilt = false;

  int currentIndex = 0;
  final PageController pageController = PageController();

  static const String _listenerId = 'VendorKycStepperController';

  /// `kyc.document` arrives on the signed-in user's own channel, so only
  /// for the vendor's own documents (the salesman is not on it).
  bool get _listensLive => vendorId == null;

  @override
  void onInit() {
    super.onInit();
    fetchDocumentsAPI();

    if (_listensLive) {
      // A status changed elsewhere (admin verified one, asked for a
      // re-upload): re-read for the review step's badges. The steps and
      // their forms are fixed once built, so nothing typed or picked on
      // the current step is lost.
      SocketService.instance.on('kyc.document', _listenerId, (_) => fetchDocumentsAPI(quiet: true));
      SocketService.instance.connect();
    }
  }

  @override
  void onClose() {
    if (_listensLive) {
      SocketService.instance.off('kyc.document', _listenerId);
    }
    for (final document in steps) {
      final tag = stepTag(document);
      if (Get.isRegistered<VendorDocumentUploadController>(tag: tag)) {
        Get.delete<VendorDocumentUploadController>(tag: tag, force: true);
      }
    }
    pageController.dispose();
    super.onClose();
  }

  /// Document steps plus the review step.
  int get totalSteps => steps.length + 1;

  bool get isReviewStep => currentIndex >= steps.length;

  VendorDocumentModel? get currentDocument => isReviewStep ? null : steps[currentIndex];

  /// The current step's title for the "Step X of N · …" line.
  String get currentStepTitle =>
      currentDocument?.documentType.name ?? tr(StringRes.kycReviewStepTitle);

  /// Unique to this screen instance, so a second stepper (or the plain
  /// upload screen, which registers untagged) never shares a form.
  String stepTag(VendorDocumentModel document) =>
      'kycStep_${identityHashCode(this)}_${document.documentType.id}';

  VendorDocumentUploadController uploadControllerFor(VendorDocumentModel document) =>
      Get.find<VendorDocumentUploadController>(tag: stepTag(document));

  /// [quiet] for the re-reads after an upload elsewhere: no error toast,
  /// and what is already on screen stays if it fails.
  Future<void> fetchDocumentsAPI({bool quiet = false}) async {
    isLoading = true;
    hasError = false;
    update();

    try {
      final response = await DataSource.instance.vendorDocumentsAPI(vendorId: vendorId);

      if (response == null || !response.isSuccess || response.data is! Map<String, dynamic>) {
        hasError = documents == null;
        if (!quiet) {
          Utils.showToast(response?.message ?? tr(StringRes.somethingWentWrong), isError: true);
        }
        return;
      }

      documents = VendorDocumentsModel.fromJson(response.data as Map<String, dynamic>);
      _buildSteps();
    } catch (e) {
      if (kDebugMode) {
        print('KYC stepper documents error $e');
      }
      hasError = documents == null;
      if (!quiet) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
      }
    } finally {
      isLoading = false;
      if (!isClosed) {
        update();
      }
    }
  }

  void _buildSteps() {
    if (_stepsBuilt || documents == null) {
      return;
    }

    _stepsBuilt = true;
    steps = documents!.compulsoryToUpload;

    for (final document in steps) {
      Get.put(
        VendorDocumentUploadController(vendorId: vendorId, document: document),
        tag: stepTag(document),
      );
    }
  }

  // ── Navigation ───────────────────────────────────────────────────────────

  void goToStep(int index) {
    if (index < 0 || index >= totalSteps) {
      return;
    }

    FocusManager.instance.primaryFocus?.unfocus();
    currentIndex = index;
    update();

    if (pageController.hasClients) {
      pageController.animateToPage(
        index,
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeOut,
      );
    }
  }

  /// Header back arrow: the previous step, or off the screen from step 1.
  void previousStep() {
    if (currentIndex == 0) {
      Get.back();
      return;
    }

    goToStep(currentIndex - 1);
  }

  /// Salesman only: documents are not required to carry on with the sale.
  void skip() => Get.back();

  // ── Actions ──────────────────────────────────────────────────────────────

  /// Uploads the current step's document, then moves to the next step. A
  /// document that turned out to be locked (verified or under review
  /// meanwhile) needs nothing more, so it moves on as well.
  Future<void> saveAndContinueAPI() async {
    final document = currentDocument;

    if (document == null) {
      return;
    }

    final upload = uploadControllerFor(document);
    final refreshed = await upload.uploadAPI();

    if (refreshed != null) {
      documents = refreshed;
      Utils.showToast(tr(StringRes.documentUploaded));
      goToStep(currentIndex + 1);
      return;
    }

    if (upload.lastFailureLocked) {
      await fetchDocumentsAPI(quiet: true);
      goToStep(currentIndex + 1);
    }
  }

  /// Review step: an optional document (or a replacement) through the
  /// ordinary upload screen; its refreshed list replaces ours.
  Future<void> openUpload(VendorDocumentModel document) async {
    if (!document.canUpload) {
      return;
    }

    final result = await Get.to<VendorDocumentsModel?>(
      () => VendorDocumentUploadView(vendorId: vendorId, document: document),
    );

    if (result != null) {
      documents = result;
      update();
      return;
    }

    await fetchDocumentsAPI(quiet: true);
  }

  /// "Submit for verification". The uploads have already gone, one per
  /// step, so this only decides where to go:
  ///  * salesman → back to Add Vendor with `true`, which refreshes its count;
  ///  * vendor → the "Documents submitted" screen. It is normally right
  ///    underneath (it opened this one), so that is a plain back — never
  ///    offAll onto a screen still in the stack. Only when it is absent is
  ///    it built fresh.
  void submitForVerification() {
    if (vendorId != null || Get.isRegistered<AccountVerificationController>()) {
      Get.back(result: true);
      return;
    }

    Utils.transitionWithOffAll(const AccountVerificationView());
  }
}
