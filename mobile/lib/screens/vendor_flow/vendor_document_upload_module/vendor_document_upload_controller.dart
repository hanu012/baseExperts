import 'dart:io';

import 'package:easy_localization/easy_localization.dart';
import 'package:image_picker/image_picker.dart';

import '../../../constants/app.export.dart';

/// Uploading (or re-uploading) one KYC document.
///
/// The form is shaped by the document type: a back image only when
/// [DocumentTypeModel.hasBack], a number only when
/// [DocumentTypeModel.hasNumber] — exactly four digits when
/// [DocumentTypeModel.maskNumber] (Aadhaar: the full number is never
/// collected) — and an expiry date only when [DocumentTypeModel.hasExpiry].
/// Submit stays disabled until every piece the type needs is present.
///
/// Photos only: no file-picker package is in the app, so PDFs cannot be
/// chosen here (the admin panel can still attach one).
///
/// On success the server answers with the vendor's full refreshed list,
/// which is handed back to [VendorDocumentsController] as the route result.
class VendorDocumentUploadController extends GetxController {
  final int? vendorId;
  final VendorDocumentModel document;

  VendorDocumentUploadController({this.vendorId, required this.document});

  TextEditingController numberController = TextEditingController();
  GlobalKey<FormState> formKey = GlobalKey<FormState>();
  AutovalidateMode autoValidateMode = AutovalidateMode.disabled;

  String? frontPath;
  String? backPath;
  DateTime? expiryDate;
  bool isSubmitting = false;

  /// The server's 422 field messages, keyed by request field (`front`,
  /// `back`, `number`, `expiry_date`), shown under the matching input.
  Map<String, String> serverErrors = {};

  final ImagePicker _picker = ImagePicker();

  DocumentTypeModel get type => document.documentType;

  // ── Picking ──────────────────────────────────────────────────────────────

  /// Same capture constraints as the Add Vendor shop photo: phones in the
  /// field on weak connections, and a full-resolution camera original is
  /// slow to send and can exceed the server's size cap.
  Future<void> pickImage({required ImageSource source, required bool back}) async {
    try {
      final file = await _picker.pickImage(
        source: source,
        maxWidth: 1600,
        imageQuality: 80,
      );

      if (file == null) {
        return;
      }

      if (back) {
        backPath = file.path;
        serverErrors.remove('back');
      } else {
        frontPath = file.path;
        serverErrors.remove('front');
      }
      update();
    } catch (e) {
      if (kDebugMode) {
        print('Document image pick error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    }
  }

  File? get frontFile => frontPath == null ? null : File(frontPath!);

  File? get backFile => backPath == null ? null : File(backPath!);

  // ── Number / expiry ──────────────────────────────────────────────────────

  void onNumberChanged(String _) {
    serverErrors.remove('number');
    update();
  }

  void setExpiryDate(DateTime? date) {
    if (date == null) {
      return;
    }

    expiryDate = date;
    serverErrors.remove('expiry_date');
    update();
  }

  /// Tomorrow at the earliest — the server rejects a date that is not in
  /// the future, and an already-expiring document is no use to verify.
  DateTime get firstExpiryDate {
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day + 1);
  }

  DateTime get lastExpiryDate => DateTime(DateTime.now().year + 30, 12, 31);

  String? get expiryDateText =>
      expiryDate == null ? null : DateFormat('d MMM yyyy').format(expiryDate!);

  static final RegExp _fourDigits = RegExp(r'^\d{4}$');

  bool get isNumberValid {
    if (!type.hasNumber) {
      return true;
    }

    final value = numberController.text.trim();

    return type.maskNumber ? _fourDigits.hasMatch(value) : value.isNotEmpty;
  }

  String? validateNumber(String? value) {
    final trimmed = (value ?? '').trim();

    if (type.maskNumber) {
      return _fourDigits.hasMatch(trimmed) ? null : tr(StringRes.enterLast4Digits);
    }

    return trimmed.isEmpty ? tr(StringRes.enterDocumentNumber) : null;
  }

  // ── Submit ───────────────────────────────────────────────────────────────

  bool get canSubmit =>
      !isSubmitting &&
      frontPath != null &&
      (!type.hasBack || backPath != null) &&
      isNumberValid &&
      (!type.hasExpiry || expiryDate != null);

  /// What goes in the multipart body besides the files. A key is only sent
  /// when the type asks for it — no empty `number` for a type without one.
  Map<String, dynamic> buildFields() {
    return {
      'document_type_id': type.id,
      if (type.hasNumber) 'number': numberController.text.trim(),
      if (type.hasExpiry && expiryDate != null)
        'expiry_date': DateFormat('yyyy-MM-dd').format(expiryDate!),
    };
  }

  /// The pushed upload screen's submit: uploads, then goes back with the
  /// refreshed list (or, when the document turned out to be locked, goes
  /// back empty-handed so the list re-reads).
  Future<void> submitAPI() async {
    final refreshed = await uploadAPI();

    if (refreshed != null) {
      Utils.showToast(tr(StringRes.documentUploaded));
      Get.back(result: refreshed);
      return;
    }

    if (lastFailureLocked) {
      Get.back();
    }
  }

  /// Set by [uploadAPI] when the server answered 409 DOCUMENT_LOCKED: the
  /// document was verified or went under review meanwhile, so there is
  /// nothing left to do on this form.
  bool lastFailureLocked = false;

  /// Validates and uploads this one document, without navigating — shared
  /// by the pushed upload screen ([submitAPI]) and each document step of
  /// the KYC stepper. Returns the server's refreshed list, or null when
  /// nothing was sent or it failed (422 field errors are then on
  /// [serverErrors], and [lastFailureLocked] says whether it was a 409).
  Future<VendorDocumentsModel?> uploadAPI() async {
    lastFailureLocked = false;

    if (type.hasNumber && !(formKey.currentState?.validate() ?? true)) {
      autoValidateMode = AutovalidateMode.onUserInteraction;
      update();
      return null;
    }

    if (!canSubmit) {
      return null;
    }

    isSubmitting = true;
    serverErrors = {};
    update();

    CommonResponse? response;

    try {
      Utils.showCircularProgressLottie(true);
      response = await DataSource.instance.uploadVendorDocumentAPI(
        vendorId: vendorId,
        fields: buildFields(),
        frontPath: frontPath!,
        backPath: type.hasBack ? backPath : null,
      );
    } catch (e) {
      if (kDebugMode) {
        print('Upload vendor document error $e');
      }
    } finally {
      Utils.showCircularProgressLottie(false);
    }

    // Settled before any navigation: once this route is popped the
    // controller is deleted, and an update() after that has no listeners.
    isSubmitting = false;
    update();

    if (response == null) {
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
      return null;
    }

    if (!response.isSuccess || response.data is! Map<String, dynamic>) {
      _showFailure(response);
      return null;
    }

    return VendorDocumentsModel.fromJson(response.data as Map<String, dynamic>);
  }

  /// 422 marks the offending inputs (and toasts the first message, in case
  /// it is for a field this form does not show). 409 DOCUMENT_LOCKED means
  /// it was verified or went under review meanwhile — nothing to do here,
  /// which [lastFailureLocked] tells the caller. 403 and anything else:
  /// toast.
  void _showFailure(CommonResponse response) {
    if (response.errorCode == 'VALIDATION_FAILED' && response.fieldErrors != null) {
      serverErrors = {
        for (final entry in response.fieldErrors!.entries)
          if (entry.value.isNotEmpty) entry.key: entry.value.first,
      };
      update();
    }

    Utils.showToast(
      response.fieldError('number') ??
          response.fieldError('expiry_date') ??
          response.fieldError('front') ??
          response.fieldError('back') ??
          response.message ??
          tr(StringRes.somethingWentWrong),
      isError: true,
    );

    lastFailureLocked = response.errorCode == 'DOCUMENT_LOCKED';
  }

  @override
  void onClose() {
    numberController.dispose();
    super.onClose();
  }
}
