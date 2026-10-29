import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../vendor_document_upload_module/vendor_document_upload_controller.dart';
import '../vendor_document_upload_module/vendor_document_upload_view.dart';
import '../vendor_documents_module/vendor_documents_view.dart';
import 'vendor_kyc_stepper_controller.dart';

/// "Document verification" stepper; see [VendorKycStepperController].
///
/// Laid out like a registration wizard: a header with a back arrow and the
/// title, a row of segmented progress bars, a "Step X of N · document"
/// line, the steps in a PageView that only moves by the buttons, and the
/// primary action pinned at the bottom.
class VendorKycStepperView extends StatelessWidget {
  /// Null for the signed-in vendor's own documents.
  final int? vendorId;

  /// Salesman only: shows "Skip for now", which just leaves.
  final bool allowSkip;

  const VendorKycStepperView({super.key, this.vendorId, this.allowSkip = false});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorKycStepperController>(
      init: VendorKycStepperController(vendorId: vendorId, allowSkip: allowSkip),
      dispose: (_) => Get.delete<VendorKycStepperController>(),
      builder: (controller) {
        final ready = controller.documents != null;

        return PopScope(
          // The system back follows the header arrow: a step back, not
          // straight off the screen.
          canPop: !ready || controller.currentIndex == 0,
          onPopInvokedWithResult: (didPop, result) {
            if (!didPop) {
              controller.previousStep();
            }
          },
          child: Scaffold(
            backgroundColor: ColorRes.backgroundColor,
            resizeToAvoidBottomInset: true,
            body: SafeArea(
              bottom: false,
              child: Column(
                children: [
                  header(controller),
                  if (ready) ...[
                    progressBars(controller),
                    10.heightSpacer,
                    stepLine(controller),
                  ],
                  Expanded(child: ready ? pages(controller) : placeholder(controller)),
                ],
              ),
            ),
            bottomNavigationBar: ready ? bottomBar(controller) : null,
          ),
        );
      },
    );
  }

  Widget header(VendorKycStepperController controller) {
    return Container(
      padding: EdgeInsets.fromLTRB(4.getSize, 4.getSize, 16.getSize, 4.getSize),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: ColorRes.borderColor)),
      ),
      child: Row(
        children: [
          IconButton(
            key: const ValueKey('kycBack'),
            onPressed: controller.previousStep,
            icon: Icon(Icons.arrow_back, size: 22.getSize, color: ColorRes.secondaryColor),
          ),
          Expanded(
            child: BaseTextDMSans(
              text: StringRes.kycStepperTitle,
              fontSize: 18,
              fontWeight: FontWeight.w600,
              color: ColorRes.secondaryColor,
              textAlign: TextAlign.start,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ).tr(),
          ),
        ],
      ),
    );
  }

  Widget progressBars(VendorKycStepperController controller) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16.getSize, 14.getSize, 16.getSize, 0),
      child: Row(
        children: List.generate(controller.totalSteps, (index) {
          final reached = index <= controller.currentIndex;

          return Expanded(
            child: Container(
              key: ValueKey('kycProgress_$index'),
              height: 4.getSize,
              margin: EdgeInsets.only(
                right: index == controller.totalSteps - 1 ? 0 : 8.getSize,
              ),
              decoration: BoxDecoration(
                color: reached ? ColorRes.primaryColor : ColorRes.borderColor,
                borderRadius: BorderRadius.circular(4.getSize),
              ),
            ),
          );
        }),
      ),
    );
  }

  Widget stepLine(VendorKycStepperController controller) {
    final step = tr(
      StringRes.kycStepOf,
      args: ['${controller.currentIndex + 1}', '${controller.totalSteps}'],
    );

    return Padding(
      padding: EdgeInsets.symmetric(horizontal: 16.getSize),
      child: Align(
        alignment: Alignment.centerLeft,
        child: Text.rich(
          key: const ValueKey('kycStepLine'),
          TextSpan(
            text: step,
            style: TextStyle(
              fontSize: 13.getFontSize,
              fontWeight: FontWeight.w600,
              color: ColorRes.primaryColor,
            ),
            children: [
              TextSpan(
                text: ' · ${controller.currentStepTitle}',
                style: TextStyle(
                  fontSize: 13.getFontSize,
                  fontWeight: FontWeight.w500,
                  color: ColorRes.secondaryColor,
                ),
              ),
            ],
          ),
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
        ),
      ),
    );
  }

  Widget placeholder(VendorKycStepperController controller) {
    if (controller.hasError) {
      return Padding(
        padding: EdgeInsets.all(24.getSize),
        child: Column(
          mainAxisSize: MainAxisSize.min,
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

    return Center(child: CupertinoActivityIndicator(color: ColorRes.primaryColor));
  }

  Widget pages(VendorKycStepperController controller) {
    return PageView.builder(
      controller: controller.pageController,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: controller.totalSteps,
      itemBuilder: (context, index) {
        final padding = EdgeInsets.fromLTRB(16.getSize, 18.getSize, 16.getSize, 24.getSize);

        if (index >= controller.steps.length) {
          return SingleChildScrollView(padding: padding, child: reviewStep(controller));
        }

        final document = controller.steps[index];

        return SingleChildScrollView(
          padding: padding,
          child: GetBuilder<VendorDocumentUploadController>(
            tag: controller.stepTag(document),
            // Owned by the stepper controller, not by this page: the page
            // is torn down whenever its step scrolls away.
            autoRemove: false,
            builder: (upload) => documentStep(upload),
          ),
        );
      },
    );
  }

  Widget documentStep(VendorDocumentUploadController upload) {
    final description = upload.type.description;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if ((description ?? '').isNotEmpty) ...[
          BaseTextDMSans(
            text: description!,
            fontSize: 12,
            color: ColorRes.grayColor,
            textAlign: TextAlign.start,
            maxLines: 4,
          ),
          16.heightSpacer,
        ],
        // The admin's reason for a re-upload leads the form.
        VendorDocumentUploadForm(controller: upload, showSubmit: false),
      ],
    );
  }

  Widget reviewStep(VendorKycStepperController controller) {
    final documents = controller.documents?.documents ?? const <VendorDocumentModel>[];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        BaseTextDMSans(
          text: StringRes.kycReviewStepDesc,
          fontSize: 12,
          color: ColorRes.grayColor,
          textAlign: TextAlign.start,
          maxLines: 4,
        ).tr(),
        16.heightSpacer,
        if (documents.isEmpty)
          BaseTextDMSans(
            text: StringRes.noDocumentTypes,
            fontSize: 14,
            color: ColorRes.grayColor,
            maxLines: 2,
          ).tr()
        else
          for (final document in documents) ...[
            reviewRow(controller, document),
            10.heightSpacer,
          ],
        8.heightSpacer,
        const AuthTipBox(
          message: StringRes.kycReviewTeamNote,
          icon: Icons.info_outline,
        ),
      ],
    );
  }

  Widget reviewRow(VendorKycStepperController controller, VendorDocumentModel document) {
    final type = document.documentType;
    final canAdd = !type.isCompulsory && document.canUpload;

    return Container(
      key: ValueKey('kycReviewRow_${type.id}'),
      padding: EdgeInsets.all(14.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceColor,
        borderRadius: BorderRadius.circular(14.getSize),
        border: Border.all(color: ColorRes.borderColor),
      ),
      child: Row(
        children: [
          Expanded(
            flex: 3,
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
                Wrap(
                  spacing: 8.getSize,
                  runSpacing: 6.getSize,
                  children: [
                    VendorDocumentsView.statusBadge(document.status),
                    if (type.isCompulsory) VendorDocumentsView.requiredChip() else optionalChip(),
                  ],
                ),
              ],
            ),
          ),
          if (canAdd) ...[
            8.widthSpacer,
            // Flexible: a long translation shrinks the action, not the row.
            Flexible(
              child: TextButton(
                key: ValueKey('kycOptionalUpload_${type.id}'),
                onPressed: () => controller.openUpload(document),
                style: TextButton.styleFrom(
                  foregroundColor: ColorRes.primaryColor,
                  padding: EdgeInsets.symmetric(horizontal: 10.getSize, vertical: 6.getSize),
                  minimumSize: Size.zero,
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
                child: BaseTextDMSans(
                  text: document.hasFile ? StringRes.kycReplaceDocument : StringRes.kycAddDocument,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: ColorRes.primaryColor,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ).tr(),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget optionalChip() {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 8.getSize, vertical: 3.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceElevatedColor,
        borderRadius: BorderRadius.circular(20.getSize),
        border: Border.all(color: ColorRes.borderColor),
      ),
      child: BaseTextDMSans(
        text: StringRes.documentOptional,
        fontSize: 10,
        fontWeight: FontWeight.w600,
        color: ColorRes.grayColor,
      ).tr(),
    );
  }

  Widget bottomBar(VendorKycStepperController controller) {
    final document = controller.currentDocument;

    return Container(
      padding: EdgeInsets.fromLTRB(16.getSize, 14.getSize, 16.getSize, 12.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceColor,
        borderRadius: BorderRadius.vertical(top: Radius.circular(22.getSize)),
        boxShadow: [
          BoxShadow(
            color: ColorRes.secondaryColor.withValues(alpha: 0.08),
            offset: Offset(0, -8.getSize),
            blurRadius: 24.getSize,
          ),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (document == null)
              primaryButton(
                key: const ValueKey('kycSubmit'),
                label: StringRes.kycSubmitForVerification,
                onPressed: controller.submitForVerification,
              )
            else
              // Follows the current step's form, so it greys out until that
              // document has everything its type needs.
              GetBuilder<VendorDocumentUploadController>(
                key: ValueKey(controller.stepTag(document)),
                tag: controller.stepTag(document),
                autoRemove: false,
                builder: (upload) => primaryButton(
                  key: const ValueKey('kycSaveContinue'),
                  label: StringRes.kycSaveContinue,
                  onPressed: upload.canSubmit ? controller.saveAndContinueAPI : null,
                ),
              ),
            if (controller.allowSkip)
              TextButton(
                key: const ValueKey('kycSkip'),
                onPressed: controller.skip,
                style: TextButton.styleFrom(foregroundColor: ColorRes.grayColor),
                child: BaseTextDMSans(
                  text: StringRes.kycSkipForNow,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: ColorRes.grayColor,
                ).tr(),
              ),
          ],
        ),
      ),
    );
  }

  Widget primaryButton({
    required Key key,
    required String label,
    required VoidCallback? onPressed,
  }) {
    final enabled = onPressed != null;

    return BaseRaisedButton(
      key: key,
      onPressed: onPressed,
      buttonText: label,
      buttonColor: enabled ? ColorRes.primaryColor : ColorRes.surfaceElevatedColor,
      textColor: enabled ? ColorRes.whiteColor : ColorRes.grayColor,
      borderColor: enabled ? null : ColorRes.borderColor,
      fontWeight: FontWeight.w600,
    );
  }
}
