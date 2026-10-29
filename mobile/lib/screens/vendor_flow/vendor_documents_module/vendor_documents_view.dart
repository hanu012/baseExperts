import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_documents_controller.dart';

/// KYC documents list; see [VendorDocumentsController].
///
/// Same chrome as the change-password screen: a gradient hero over a plain
/// scrolling column. Each document is a card — name, "Required" chip,
/// status badge, number/expiry when on file, the admin's reason when a
/// re-upload was asked for, and the Upload action while it is allowed.
class VendorDocumentsView extends StatelessWidget {
  /// Null for the signed-in vendor's own documents.
  final int? vendorId;

  const VendorDocumentsView({super.key, this.vendorId});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorDocumentsController>(
      init: VendorDocumentsController(vendorId: vendorId),
      dispose: (_) => Get.delete<VendorDocumentsController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ColorRes.backgroundColor,
          body: RefreshIndicator(
            onRefresh: controller.fetchDocumentsAPI,
            color: ColorRes.primaryColor,
            backgroundColor: ColorRes.surfaceColor,
            child: ListView(
              padding: EdgeInsets.zero,
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                AuthGradientHero(
                  icon: Icons.verified_user_outlined,
                  title: tr(StringRes.vendorDocumentsTitle),
                  subtitle: tr(StringRes.vendorDocumentsDesc),
                  trailing: HeroIconButton(
                    icon: Icons.close,
                    onTap: () => Get.back(),
                  ),
                ),
                Padding(
                  padding: EdgeInsets.fromLTRB(
                    20.getSize,
                    20.getSize,
                    20.getSize,
                    30.getSize,
                  ),
                  child: content(controller),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget content(VendorDocumentsController controller) {
    final data = controller.documents;

    if (data == null) {
      if (controller.hasError) {
        return errorState(controller);
      }

      return Padding(
        padding: EdgeInsets.only(top: 40.getSize),
        child: CupertinoActivityIndicator(color: ColorRes.primaryColor),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (controller.showMissingBanner)
          banner(
            text: tr(
              StringRes.missingCompulsoryBanner,
              args: [controller.missingCompulsory.join(', ')],
            ),
            color: ColorRes.warningColor,
            icon: Icons.info_outline,
          )
        else if (data.complete)
          banner(
            text: tr(StringRes.documentsComplete),
            color: ColorRes.successColor,
            icon: Icons.verified_outlined,
          ),
        16.heightSpacer,
        if (data.documents.isEmpty)
          BaseTextDMSans(
            text: StringRes.noDocumentTypes,
            fontSize: 14,
            color: ColorRes.grayColor,
            maxLines: 2,
          ).tr()
        else
          for (final document in data.documents) ...[
            documentCard(controller, document),
            12.heightSpacer,
          ],
      ],
    );
  }

  Widget errorState(VendorDocumentsController controller) {
    return Padding(
      padding: EdgeInsets.only(top: 24.getSize),
      child: Column(
        children: [
          BaseTextDMSans(
            text: StringRes.somethingWentWrong,
            fontSize: 14,
            color: ColorRes.grayColor,
          ).tr(),
          16.heightSpacer,
          BaseRaisedButton(
            onPressed: controller.fetchDocumentsAPI,
            buttonText: StringRes.retry,
            buttonColor: ColorRes.primaryColor,
          ),
        ],
      ),
    );
  }

  Widget banner({
    required String text,
    required Color color,
    required IconData icon,
  }) {
    return Container(
      key: const ValueKey('documentsBanner'),
      padding: EdgeInsets.all(14.getSize),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(14.getSize),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18.getSize, color: color),
          10.widthSpacer,
          Expanded(
            child: BaseTextDMSans(
              text: text,
              fontSize: 12,
              fontWeight: FontWeight.w500,
              color: ColorRes.secondaryColor,
              textAlign: TextAlign.start,
              maxLines: 5,
            ),
          ),
        ],
      ),
    );
  }

  Widget documentCard(
    VendorDocumentsController controller,
    VendorDocumentModel document,
  ) {
    final type = document.documentType;
    final showNote = document.status == VendorDocumentModel.statusReupload &&
        (document.reviewNote ?? '').isNotEmpty;

    return Container(
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceColor,
        borderRadius: BorderRadius.circular(16.getSize),
        border: Border.all(color: ColorRes.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          BaseTextDMSans(
            text: type.name ?? '',
            fontSize: 14,
            fontWeight: FontWeight.w600,
            color: ColorRes.secondaryColor,
            textAlign: TextAlign.start,
            maxLines: 2,
          ),
          8.heightSpacer,
          // Badges wrap rather than share the name's row: translated
          // status labels run long on a narrow phone.
          Wrap(
            spacing: 8.getSize,
            runSpacing: 6.getSize,
            children: [
              statusBadge(document.status),
              if (type.isCompulsory) requiredChip(),
            ],
          ),
          if ((type.description ?? '').isNotEmpty) ...[
            6.heightSpacer,
            BaseTextDMSans(
              text: type.description!,
              fontSize: 11,
              color: ColorRes.grayColor,
              textAlign: TextAlign.start,
              maxLines: 3,
            ),
          ],
          if ((document.number ?? '').isNotEmpty) ...[
            10.heightSpacer,
            detailLine(
              type.numberLabel ?? tr(StringRes.documentNumber),
              document.number!,
            ),
          ],
          if ((document.expiryDate ?? '').isNotEmpty) ...[
            6.heightSpacer,
            detailLine(
              tr(StringRes.documentExpiresOn),
              VendorDocumentsController.formatDate(document.expiryDate!),
            ),
          ],
          // What was uploaded, so the uploader can check it.
          if ((document.frontUrl ?? '').isNotEmpty || (document.backUrl ?? '').isNotEmpty) ...[
            12.heightSpacer,
            Row(
              children: [
                if ((document.frontUrl ?? '').isNotEmpty)
                  filePreview(controller, document.frontUrl!, StringRes.documentFrontSide),
                if ((document.frontUrl ?? '').isNotEmpty && (document.backUrl ?? '').isNotEmpty)
                  10.widthSpacer,
                if ((document.backUrl ?? '').isNotEmpty)
                  filePreview(controller, document.backUrl!, StringRes.documentBackSide),
              ],
            ),
          ],
          if (showNote) ...[
            12.heightSpacer,
            reviewNote(document.reviewNote!),
          ],
          if (document.canUpload) ...[
            14.heightSpacer,
            BaseRaisedButton(
              key: ValueKey('uploadDocument_${type.id}'),
              onPressed: () => controller.openUpload(document),
              buttonText: document.hasFile
                  ? StringRes.reuploadDocument
                  : StringRes.uploadDocument,
              buttonColor: ColorRes.primaryColor,
              textColor: ColorRes.whiteColor,
              fontWeight: FontWeight.w600,
              buttonVerticalPadding: 10.getSize,
              icon: Icon(
                Icons.upload_outlined,
                size: 18.getSize,
                color: ColorRes.whiteColor,
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// A small thumbnail (or a PDF tile) that opens the file full size.
  Widget filePreview(VendorDocumentsController controller, String url, String labelKey) {
    final isPdf = VendorDocumentsController.isPdf(url);

    return InkWell(
      key: ValueKey('preview_$labelKey'),
      onTap: () => controller.openFile(url, photoViewer: photoViewer),
      borderRadius: BorderRadius.circular(10.getSize),
      child: Column(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(10.getSize),
            child: Container(
              width: 72.getSize,
              height: 56.getSize,
              color: ColorRes.backgroundColor,
              child: isPdf
                  ? Icon(Icons.picture_as_pdf_outlined, color: ColorRes.errorColor, size: 26.getSize)
                  : Image.network(
                      url,
                      fit: BoxFit.cover,
                      errorBuilder: (_, _, _) => Icon(
                        Icons.broken_image_outlined,
                        color: ColorRes.grayColor,
                        size: 22.getSize,
                      ),
                    ),
            ),
          ),
          4.heightSpacer,
          BaseTextDMSans(
            text: labelKey,
            fontSize: 10,
            color: ColorRes.grayColor,
          ).tr(),
        ],
      ),
    );
  }

  /// Full-screen photo with pinch-to-zoom. Dark backdrop on purpose: it is
  /// a viewer, and the photo is the only thing on it.
  Widget photoViewer(String url) {
    return Scaffold(
      backgroundColor: ColorRes.blackColor,
      appBar: AppBar(
        backgroundColor: ColorRes.blackColor,
        iconTheme: IconThemeData(color: ColorRes.whiteColor),
        elevation: 0,
      ),
      body: Center(
        child: InteractiveViewer(
          maxScale: 5,
          child: Image.network(
            url,
            fit: BoxFit.contain,
            errorBuilder: (_, _, _) => BaseTextDMSans(
              text: StringRes.somethingWentWrong,
              color: ColorRes.whiteColor,
            ).tr(),
          ),
        ),
      ),
    );
  }

  static Widget requiredChip() {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 8.getSize, vertical: 3.getSize),
      decoration: BoxDecoration(
        color: ColorRes.primaryColor.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(20.getSize),
        border: Border.all(color: ColorRes.primaryColor.withValues(alpha: 0.20)),
      ),
      child: BaseTextDMSans(
        text: StringRes.documentRequired,
        fontSize: 10,
        fontWeight: FontWeight.w600,
        color: ColorRes.primaryColor,
      ).tr(),
    );
  }

  static Widget statusBadge(String status) {
    final color = statusColor(status);

    return Container(
      padding: EdgeInsets.symmetric(horizontal: 10.getSize, vertical: 4.getSize),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20.getSize),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: BaseTextDMSans(
        text: VendorDocumentsController.statusLabel(status),
        fontSize: 10,
        fontWeight: FontWeight.w600,
        color: color,
        maxLines: 1,
      ).tr(),
    );
  }

  /// Verified green, re-upload red, expired amber; the in-flight states
  /// teal; nothing uploaded yet a neutral grey.
  static Color statusColor(String status) {
    switch (status) {
      case VendorDocumentModel.statusVerified:
        return ColorRes.successColor;
      case VendorDocumentModel.statusReupload:
        return ColorRes.errorColor;
      case VendorDocumentModel.statusExpired:
        return ColorRes.warningColor;
      case VendorDocumentModel.statusUploaded:
      case VendorDocumentModel.statusInVerification:
        return ColorRes.primaryColor;
      default:
        return ColorRes.grayColor;
    }
  }

  Widget detailLine(String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Flexible(
          child: BaseTextDMSans(
            text: '$label: ',
            fontSize: 12,
            color: ColorRes.grayColor,
            textAlign: TextAlign.start,
            maxLines: 1,
          ),
        ),
        Flexible(
          child: BaseTextDMSans(
            text: value,
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: ColorRes.secondaryColor,
            textAlign: TextAlign.start,
            maxLines: 1,
          ),
        ),
      ],
    );
  }

  /// The admin's reason, verbatim — the only thing that tells the uploader
  /// what was wrong with the last attempt.
  Widget reviewNote(String note) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(12.getSize),
      decoration: BoxDecoration(
        color: ColorRes.errorColor.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12.getSize),
        border: Border.all(color: ColorRes.errorColor.withValues(alpha: 0.35)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          BaseTextDMSans(
            text: StringRes.documentReviewNote,
            fontSize: 11,
            fontWeight: FontWeight.w600,
            color: ColorRes.errorColor,
            textAlign: TextAlign.start,
          ).tr(),
          4.heightSpacer,
          BaseTextDMSans(
            text: note,
            fontSize: 12,
            color: ColorRes.secondaryColor,
            textAlign: TextAlign.start,
            maxLines: 6,
          ),
        ],
      ),
    );
  }
}
