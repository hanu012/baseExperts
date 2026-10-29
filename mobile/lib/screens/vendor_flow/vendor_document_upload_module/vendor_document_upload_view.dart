import 'dart:io';

import 'package:easy_localization/easy_localization.dart';
import 'package:image_picker/image_picker.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_document_upload_controller.dart';

/// Upload form for one KYC document; see [VendorDocumentUploadController].
///
/// A pushed screen rather than a bottom sheet: it carries up to two photo
/// previews, a text field and a date, which is more than a sheet holds
/// comfortably with the keyboard up. Built like the change-password screen
/// — gradient hero, then the form in a scrolling column, CTA included.
class VendorDocumentUploadView extends StatelessWidget {
  final int? vendorId;
  final VendorDocumentModel document;

  const VendorDocumentUploadView({
    super.key,
    this.vendorId,
    required this.document,
  });

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorDocumentUploadController>(
      init: VendorDocumentUploadController(vendorId: vendorId, document: document),
      dispose: (_) => Get.delete<VendorDocumentUploadController>(),
      builder: (controller) {
        final type = controller.type;

        return Scaffold(
          backgroundColor: ColorRes.backgroundColor,
          resizeToAvoidBottomInset: true,
          body: ListView(
            padding: EdgeInsets.zero,
            children: [
              AuthGradientHero(
                icon: Icons.badge_outlined,
                title: tr(StringRes.uploadDocumentTitle, args: [type.name ?? '']),
                subtitle: type.description,
                trailing: HeroIconButton(
                  icon: Icons.close,
                  onTap: () => Get.back(),
                ),
              ),
              Padding(
                padding: EdgeInsets.fromLTRB(
                  24.getSize,
                  24.getSize,
                  24.getSize,
                  30.getSize,
                ),
                child: VendorDocumentUploadForm(controller: controller),
              ),
            ],
          ),
        );
      },
    );
  }
}

/// The upload form's body — review note, photo pickers, number, expiry and
/// tip — driven by one [VendorDocumentUploadController]. Shared by the
/// pushed [VendorDocumentUploadView] (which shows its own submit button)
/// and each document step of the KYC stepper (which does not: the
/// stepper's bottom "Save & continue" calls
/// [VendorDocumentUploadController.uploadAPI] instead).
class VendorDocumentUploadForm extends StatelessWidget {
  final VendorDocumentUploadController controller;

  /// False inside the stepper, whose own bottom button submits.
  final bool showSubmit;

  const VendorDocumentUploadForm({
    super.key,
    required this.controller,
    this.showSubmit = true,
  });

  @override
  Widget build(BuildContext context) {
    final type = controller.type;

    return Form(
      key: controller.formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Re-upload: the admin's reason stays in view while fixing it.
          if (controller.document.status == VendorDocumentModel.statusReupload &&
              (controller.document.reviewNote ?? '').isNotEmpty) ...[
            reviewNote(controller.document.reviewNote!),
            20.heightSpacer,
          ],

          fieldLabel(tr(type.hasBack ? StringRes.documentFrontSide : StringRes.documentPhoto)),
          8.heightSpacer,
          photoTile(
            key: const ValueKey('documentFront'),
            hasFile: controller.frontPath != null,
            preview: controller.frontFile,
            onTap: () => pickFor(controller, back: false),
          ),
          errorText(controller.serverErrors['front']),

          if (type.hasBack) ...[
            20.heightSpacer,
            fieldLabel(tr(StringRes.documentBackSide)),
            8.heightSpacer,
            photoTile(
              key: const ValueKey('documentBack'),
              hasFile: controller.backPath != null,
              preview: controller.backFile,
              onTap: () => pickFor(controller, back: true),
            ),
            errorText(controller.serverErrors['back']),
          ],

          if (type.hasNumber) ...[
            20.heightSpacer,
            fieldLabel(type.numberLabel ?? tr(StringRes.documentNumber)),
            8.heightSpacer,
            BaseTextField(
              key: const ValueKey('documentNumber'),
              controller: controller.numberController,
              hintText: type.numberHint ?? tr(StringRes.enterDocumentNumber),
              isShowBorder: true,
              validateMode: controller.autoValidateMode,
              validator: controller.validateNumber,
              onChanged: controller.onNumberChanged,
              textInputAction: TextInputAction.done,
              textInputType: type.maskNumber ? TextInputType.number : TextInputType.text,
              textCapitalization: type.maskNumber
                  ? TextCapitalization.none
                  : TextCapitalization.characters,
              maxLength: type.maskNumber ? 4 : null,
              inputFormatters: type.maskNumber
                  ? [
                      FilteringTextInputFormatter.digitsOnly,
                      LengthLimitingTextInputFormatter(4),
                    ]
                  : null,
            ),
            errorText(controller.serverErrors['number']),
          ],

          if (type.hasExpiry) ...[
            20.heightSpacer,
            fieldLabel(tr(StringRes.documentExpiryDate)),
            8.heightSpacer,
            expiryTile(context, controller),
            errorText(controller.serverErrors['expiry_date']),
          ],

          20.heightSpacer,
          const AuthTipBox(
            message: StringRes.documentPhotoTip,
            icon: Icons.info_outline,
          ),

          if (showSubmit) ...[
            28.heightSpacer,
            // Disabled (greyed) until every piece the type needs is present.
            BaseRaisedButton(
              key: const ValueKey('submitDocument'),
              onPressed: controller.canSubmit ? controller.submitAPI : null,
              buttonText: StringRes.submitDocument,
              buttonColor: controller.canSubmit
                  ? ColorRes.primaryColor
                  : ColorRes.surfaceElevatedColor,
              textColor: controller.canSubmit ? ColorRes.whiteColor : ColorRes.grayColor,
              borderColor: controller.canSubmit ? null : ColorRes.borderColor,
              fontWeight: FontWeight.w600,
            ),
          ],
        ],
      ),
    );
  }

  /// Camera or gallery, as on the profile photo sheet.
  Future<void> pickFor(
    VendorDocumentUploadController controller, {
    required bool back,
  }) async {
    final source = await Get.bottomSheet<ImageSource>(
      sourceSheet(),
      isScrollControlled: true,
    );

    if (source != null) {
      await controller.pickImage(source: source, back: back);
    }
  }

  Widget sourceSheet() {
    return Container(
      padding: EdgeInsets.fromLTRB(16.getSize, 12.getSize, 16.getSize, 16.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceColor,
        borderRadius: BorderRadius.vertical(top: Radius.circular(22.getSize)),
      ),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                height: 4.getSize,
                width: 46.getSize,
                decoration: BoxDecoration(
                  color: ColorRes.borderColor,
                  borderRadius: BorderRadius.circular(3.getSize),
                ),
              ),
            ),
            16.heightSpacer,
            BaseTextDMSans(
              text: tr(StringRes.addDocumentPhoto),
              fontSize: 16,
              fontWeight: FontWeight.w700,
              color: ColorRes.secondaryColor,
              textAlign: TextAlign.start,
            ),
            8.heightSpacer,
            sourceOption(Icons.photo_camera_outlined, StringRes.takePhoto, ImageSource.camera),
            sourceOption(
              Icons.photo_library_outlined,
              StringRes.chooseFromGallery,
              ImageSource.gallery,
            ),
          ],
        ),
      ),
    );
  }

  Widget sourceOption(IconData icon, String labelKey, ImageSource source) {
    return InkWell(
      onTap: () => Get.back(result: source),
      borderRadius: BorderRadius.circular(12.getSize),
      child: Padding(
        padding: EdgeInsets.symmetric(vertical: 14.getSize, horizontal: 4.getSize),
        child: Row(
          children: [
            Icon(icon, size: 22.getSize, color: ColorRes.primaryColor),
            14.widthSpacer,
            BaseTextDMSans(
              text: tr(labelKey),
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: ColorRes.secondaryColor,
            ),
          ],
        ),
      ),
    );
  }

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

  Widget fieldLabel(String text) {
    return BaseTextDMSans(
      text: text,
      fontWeight: FontWeight.w600,
      fontSize: 14,
      color: ColorRes.secondaryColor,
      textAlign: TextAlign.start,
    );
  }

  Widget errorText(String? message) {
    if (message == null) {
      return const SizedBox.shrink();
    }

    return Padding(
      padding: EdgeInsets.only(top: 6.getSize, left: 4.getSize),
      child: BaseTextDMSans(
        text: message,
        fontSize: 11,
        color: ColorRes.errorColor,
        textAlign: TextAlign.start,
        maxLines: 3,
      ),
    );
  }

  /// Same tile as the Add Vendor shop photo, with a thumbnail once chosen.
  Widget photoTile({
    required Key key,
    required bool hasFile,
    required File? preview,
    required VoidCallback onTap,
  }) {
    return InkWell(
      key: key,
      onTap: onTap,
      borderRadius: BorderRadius.circular(12.getSize),
      child: Container(
        padding: EdgeInsets.symmetric(horizontal: 14.getSize, vertical: 12.getSize),
        decoration: BoxDecoration(
          color: ColorRes.surfaceColor,
          borderRadius: BorderRadius.circular(12.getSize),
          border: Border.all(
            color: hasFile ? ColorRes.primaryColor : ColorRes.borderColor,
          ),
        ),
        child: Row(
          children: [
            Container(
              height: 48.getSize,
              width: 48.getSize,
              decoration: BoxDecoration(
                color: ColorRes.primaryColor.withValues(alpha: 0.10),
                borderRadius: BorderRadius.circular(10.getSize),
              ),
              clipBehavior: Clip.antiAlias,
              child: preview == null
                  ? Icon(
                      Icons.add_a_photo_outlined,
                      size: 20.getSize,
                      color: ColorRes.primaryColor,
                    )
                  : Image.file(
                      preview,
                      fit: BoxFit.cover,
                      errorBuilder: (context, error, stack) => Icon(
                        Icons.image_outlined,
                        size: 20.getSize,
                        color: ColorRes.primaryColor,
                      ),
                    ),
            ),
            12.widthSpacer,
            Expanded(
              child: BaseTextDMSans(
                text: hasFile ? StringRes.replaceImage : StringRes.tapToUpload,
                fontSize: 13,
                fontWeight: FontWeight.w500,
                color: ColorRes.secondaryColor,
                textAlign: TextAlign.start,
              ).tr(),
            ),
            Icon(
              hasFile ? Icons.check_circle_outline : Icons.upload_outlined,
              size: 20.getSize,
              color: hasFile ? ColorRes.primaryColor : ColorRes.grayColor,
            ),
          ],
        ),
      ),
    );
  }

  Widget expiryTile(BuildContext context, VendorDocumentUploadController controller) {
    final text = controller.expiryDateText;

    return InkWell(
      key: const ValueKey('documentExpiry'),
      onTap: () async {
        final picked = await showDatePicker(
          context: context,
          initialDate: controller.expiryDate ?? controller.firstExpiryDate,
          firstDate: controller.firstExpiryDate,
          lastDate: controller.lastExpiryDate,
        );
        controller.setExpiryDate(picked);
      },
      borderRadius: BorderRadius.circular(12.getSize),
      child: Container(
        padding: EdgeInsets.symmetric(horizontal: 16.getSize, vertical: 14.getSize),
        decoration: BoxDecoration(
          color: ColorRes.surfaceElevatedColor,
          borderRadius: BorderRadius.circular(12.getSize),
          border: Border.all(
            color: text == null ? ColorRes.borderColor : ColorRes.primaryColor,
          ),
        ),
        child: Row(
          children: [
            Icon(Icons.event_outlined, size: 20.getSize, color: ColorRes.primaryColor),
            12.widthSpacer,
            Expanded(
              child: BaseTextDMSans(
                text: text ?? tr(StringRes.selectExpiryDate),
                fontSize: 14,
                fontWeight: text == null ? FontWeight.w400 : FontWeight.w600,
                color: text == null ? ColorRes.grayColor : ColorRes.secondaryColor,
                textAlign: TextAlign.start,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
