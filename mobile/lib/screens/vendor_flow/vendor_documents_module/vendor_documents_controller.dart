import 'dart:async';

import 'package:easy_localization/easy_localization.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../constants/app.export.dart';
import '../../../utils/socket_service.dart';
import '../vendor_document_upload_module/vendor_document_upload_view.dart';

/// A vendor's KYC documents — one per admin-defined type, each with its own
/// status. Shared by two apps:
///
///  * the vendor app, for the vendor's own ([vendorId] null →
///    `vendors/me/documents`, reachable while the account is still pending
///    approval and later when admin asks for a re-upload or one expires);
///  * the salesman app, for a vendor that salesman onboarded.
///
/// Admin can only approve the account once every compulsory type is
/// Verified, so the screen leads with what is still missing.
///
/// Re-reads on resume: a verification decided while the app was away
/// should not need a manual pull to show up.
class VendorDocumentsController extends GetxController {
  final int? vendorId;

  VendorDocumentsController({this.vendorId});

  VendorDocumentsModel? documents;
  bool isLoading = false;
  bool hasError = false;

  StreamSubscription<bool>? _resumeSubscription;

  static const String _listenerId = 'VendorDocumentsController';

  /// Live status changes arrive as `kyc.document` on the signed-in user's
  /// own channel — so only for the vendor's own documents; a salesman is
  /// not on that vendor's channel.
  bool get _listensLive => vendorId == null;

  @override
  void onInit() {
    super.onInit();
    fetchDocumentsAPI();
    _resumeSubscription =
        SocketService.instance.appResumedStream.listen((_) => fetchDocumentsAPI(quiet: true));

    if (_listensLive) {
      // Only a hint: the list is re-read, not patched from the payload.
      SocketService.instance.on('kyc.document', _listenerId, (_) => fetchDocumentsAPI(quiet: true));
      SocketService.instance.connect();
    }
  }

  @override
  void onClose() {
    _resumeSubscription?.cancel();
    if (_listensLive) {
      SocketService.instance.off('kyc.document', _listenerId);
    }
    super.onClose();
  }

  List<String> get missingCompulsory => documents?.missingCompulsory ?? const [];

  bool get showMissingBanner => missingCompulsory.isNotEmpty;

  /// [quiet] for the automatic re-reads (resume, return from the upload
  /// form): no error toast for something the user did not ask for.
  Future<void> fetchDocumentsAPI({bool quiet = false}) async {
    isLoading = true;
    hasError = false;
    update();

    try {
      final response = await DataSource.instance.vendorDocumentsAPI(vendorId: vendorId);

      if (response == null || !response.isSuccess || response.data is! Map<String, dynamic>) {
        // Keeps what is already on screen on a failed quiet refresh rather
        // than blanking a list that was fine a moment ago.
        hasError = documents == null;
        if (!quiet) {
          Utils.showToast(
            response?.message ?? tr(StringRes.somethingWentWrong),
            isError: true,
          );
        }
        return;
      }

      documents = VendorDocumentsModel.fromJson(response.data as Map<String, dynamic>);
    } catch (e) {
      if (kDebugMode) {
        print('Fetch vendor documents error $e');
      }
      hasError = documents == null;
      if (!quiet) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
      }
    } finally {
      isLoading = false;
      update();
    }
  }

  /// Opens the upload form for one document. A successful upload hands back
  /// the server's refreshed list, which replaces ours outright; anything
  /// else (backed out, or the document turned out to be locked) re-reads
  /// quietly so the screen never shows a stale status.
  Future<void> openUpload(VendorDocumentModel document) async {
    if (!document.canUpload) {
      return;
    }

    final result = await Get.to<VendorDocumentsModel?>(
      () => VendorDocumentUploadView(vendorId: vendorId, document: document),
    );

    if (result != null) {
      documents = result;
      hasError = false;
      update();
      return;
    }

    await fetchDocumentsAPI(quiet: true);
  }

  /// Whether a file link points at a PDF rather than a photo. The signed
  /// link carries a query string, so the path is checked, not the URL.
  static bool isPdf(String url) =>
      (Uri.tryParse(url)?.path ?? url).toLowerCase().endsWith('.pdf');

  /// Shows an uploaded file: a photo full-screen with pinch-to-zoom, a PDF
  /// in the phone's own viewer. Links are signed and short-lived, so a
  /// stale one is refreshed first by re-reading the list.
  Future<void> openFile(String url, {required Widget Function(String url) photoViewer}) async {
    if (isPdf(url)) {
      final uri = Uri.tryParse(url);

      if (uri == null || !await launchUrl(uri, mode: LaunchMode.externalApplication)) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
      }
      return;
    }

    await Get.to(() => photoViewer(url));
  }

  /// Translation key for a status value. Unknown values fall back to the
  /// neutral "Pending upload" rather than showing a raw API string.
  static String statusLabel(String status) {
    switch (status) {
      case VendorDocumentModel.statusUploaded:
        return StringRes.docStatusUploaded;
      case VendorDocumentModel.statusInVerification:
        return StringRes.docStatusInVerification;
      case VendorDocumentModel.statusVerified:
        return StringRes.docStatusVerified;
      case VendorDocumentModel.statusReupload:
        return StringRes.docStatusReupload;
      case VendorDocumentModel.statusExpired:
        return StringRes.docStatusExpired;
      default:
        return StringRes.docStatusPendingUpload;
    }
  }

  /// "2027-01-31" → "31 Jan 2027"; the raw string if it does not parse.
  static String formatDate(String value) {
    final parsed = DateTime.tryParse(value);

    if (parsed == null) {
      return value;
    }

    return DateFormat('d MMM yyyy').format(parsed);
  }
}
