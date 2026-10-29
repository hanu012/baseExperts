/// One admin-defined KYC document type (Aadhaar, PAN, GST, ...), as nested
/// in every `GET`/`POST /api/vendors/{me|id}/documents` row.
///
/// The flags decide what the upload form asks for: a number field only when
/// [hasNumber], a back image only when [hasBack], an expiry date only when
/// [hasExpiry]. [maskNumber] means the server only ever takes the last four
/// digits (Aadhaar) — the form collects exactly those.
class DocumentTypeModel {
  int? id;
  String? code;
  String? name;
  String? description;
  bool hasNumber;
  String? numberLabel;
  String? numberHint;
  bool maskNumber;
  bool hasExpiry;
  bool hasBack;
  bool isCompulsory;

  DocumentTypeModel({
    this.id,
    this.code,
    this.name,
    this.description,
    this.hasNumber = false,
    this.numberLabel,
    this.numberHint,
    this.maskNumber = false,
    this.hasExpiry = false,
    this.hasBack = false,
    this.isCompulsory = false,
  });

  DocumentTypeModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        code = json['code'] as String?,
        name = json['name'] as String?,
        description = json['description'] as String?,
        hasNumber = json['has_number'] == true,
        numberLabel = json['number_label'] as String?,
        numberHint = json['number_hint'] as String?,
        maskNumber = json['mask_number'] == true,
        hasExpiry = json['has_expiry'] == true,
        hasBack = json['has_back'] == true,
        isCompulsory = json['is_compulsory'] == true;
}

/// The vendor's one document of a given type, with its own status.
///
/// [id] is null while nothing has been uploaded for the type yet — the row
/// still exists so the screen can list every type the admin asks for.
class VendorDocumentModel {
  static const String statusPendingUpload = 'pending_upload';
  static const String statusUploaded = 'uploaded';
  static const String statusInVerification = 'in_verification';
  static const String statusVerified = 'verified';
  static const String statusReupload = 'reupload';
  static const String statusExpired = 'expired';

  int? id;
  DocumentTypeModel documentType;
  String status;
  String? number;
  String? expiryDate;
  String? frontUrl;
  String? backUrl;
  String? reviewNote;
  String? uploadedAt;
  bool canUpload;

  VendorDocumentModel({
    this.id,
    DocumentTypeModel? documentType,
    this.status = statusPendingUpload,
    this.number,
    this.expiryDate,
    this.frontUrl,
    this.backUrl,
    this.reviewNote,
    this.uploadedAt,
    this.canUpload = true,
  }) : documentType = documentType ?? DocumentTypeModel();

  VendorDocumentModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        documentType = json['document_type'] is Map<String, dynamic>
            ? DocumentTypeModel.fromJson(json['document_type'] as Map<String, dynamic>)
            : DocumentTypeModel(),
        status = (json['status'] as String?) ?? statusPendingUpload,
        number = json['number'] as String?,
        expiryDate = json['expiry_date'] as String?,
        frontUrl = json['front_url'] as String?,
        backUrl = json['back_url'] as String?,
        reviewNote = json['review_note'] as String?,
        uploadedAt = json['uploaded_at'] as String?,
        canUpload = json['can_upload'] == true;

  /// Something is on file and with (or past) the admin — the count the
  /// salesman's "2 of 2 compulsory uploaded" progress uses.
  bool get isSubmitted =>
      status == statusUploaded || status == statusInVerification || status == statusVerified;

  /// A document has been sent before, so the action reads "Re-upload".
  bool get hasFile => id != null && status != statusPendingUpload;

  /// Still waiting on the uploader: nothing usable on file (pending upload,
  /// sent back for a re-upload, or expired) and the server takes a new one.
  bool get awaitsUpload => canUpload && !isSubmitted;
}

/// The whole documents envelope — every type's document, the compulsory
/// types still not Verified, and whether every compulsory one is.
class VendorDocumentsModel {
  List<VendorDocumentModel> documents;
  List<String> missingCompulsory;
  bool complete;

  VendorDocumentsModel({
    List<VendorDocumentModel>? documents,
    List<String>? missingCompulsory,
    this.complete = false,
  })  : documents = documents ?? [],
        missingCompulsory = missingCompulsory ?? [];

  VendorDocumentsModel.fromJson(Map<String, dynamic> json)
      : documents = ((json['documents'] as List<dynamic>?) ?? [])
            .map((e) => VendorDocumentModel.fromJson(e as Map<String, dynamic>))
            .toList(),
        missingCompulsory = ((json['missing_compulsory'] as List<dynamic>?) ?? [])
            .map((e) => e.toString())
            .toList(),
        complete = json['complete'] == true;

  int get compulsoryCount => documents.where((d) => d.documentType.isCompulsory).length;

  /// Compulsory documents still to be sent, in server order — the document
  /// steps of the KYC stepper, and what makes the vendor's pending screen
  /// open it by itself.
  List<VendorDocumentModel> get compulsoryToUpload =>
      documents.where((d) => d.documentType.isCompulsory && d.awaitsUpload).toList();

  int get compulsorySubmittedCount =>
      documents.where((d) => d.documentType.isCompulsory && d.isSubmitted).length;
}
