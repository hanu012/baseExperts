import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import '../../vendor_flow/vendor_documents_module/vendor_documents_controller.dart';
import '../../vendor_flow/vendor_documents_module/vendor_documents_view.dart';
import 'account_verification_controller.dart';

/// "Your account is in verification" (SPEC section 3.1).
///
/// Deliberately a dead end apart from two controls. Nothing links back
/// into the app from here — the account cannot use any of it until an
/// admin decides, and offering a way in that the API then refuses reads
/// as a broken app rather than a waiting one.
///
/// The three-step tracker exists because "we are reviewing you" with no
/// sense of where that sits is the kind of screen people reinstall the app
/// over. It shows what has happened, what is happening, and what is next.
class AccountVerificationView extends StatelessWidget {
  const AccountVerificationView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<AccountVerificationController>(
      init: AccountVerificationController(),
      dispose: (_) => Get.delete<AccountVerificationController>(),
      builder: (controller) {
        final rejected = controller.isRejected;

        return PopScope(
          // The back gesture must not escape this screen: on the vendor
          // and customer flavours it would pop straight back onto the
          // registration form behind it.
          canPop: false,
          child: Scaffold(
            backgroundColor: ColorRes.backgroundColor,
            body: SafeArea(
              child: Column(
                children: [
                  Padding(
                    padding: EdgeInsets.fromLTRB(
                      24.getSize,
                      10.getSize,
                      24.getSize,
                      0,
                    ),
                    child: Align(
                      alignment: Alignment.centerRight,
                      child: signOutPill(controller),
                    ),
                  ),
                  Expanded(
                    child: ListView(
                      padding: EdgeInsets.fromLTRB(
                        24.getSize,
                        24.getSize,
                        24.getSize,
                        24.getSize,
                      ),
                      // Vendor app, still pending: the documents-submitted
                      // result. Everything else keeps the tracker below.
                      children: controller.showDocuments
                          ? documentsResult(controller)
                          : [
                        Center(child: statusBadge(rejected)),
                        22.heightSpacer,
                        BaseTextDMSans(
                          text: rejected
                              ? tr(StringRes.accountRejectedTitle)
                              : tr(StringRes.accountVerificationTitle),
                          fontSize: 18,
                          fontWeight: FontWeight.w600,
                          color: ColorRes.secondaryColor,
                          textAlign: TextAlign.center,
                          maxLines: 2,
                        ),
                        10.heightSpacer,
                        BaseTextDMSans(
                          text: rejected
                              ? tr(StringRes.accountRejectedDesc)
                              : tr(StringRes.accountVerificationDesc),
                          fontSize: 12,
                          color: ColorRes.grayColor,
                          textAlign: TextAlign.center,
                          maxLines: 4,
                        ),
                        24.heightSpacer,
                        tracker(rejected),

                        // The admin's reason, where there is one. Shown
                        // verbatim — it is the only thing that tells the
                        // user what to do next.
                        if (rejected && (controller.note ?? '').isNotEmpty) ...[
                          16.heightSpacer,
                          reasonBox(controller.note!),
                        ],
                      ],
                    ),
                  ),
                  Padding(
                    padding: EdgeInsets.fromLTRB(
                      24.getSize,
                      0,
                      24.getSize,
                      20.getSize,
                    ),
                    // Hidden once rejected: re-checking a decision that
                    // has already gone against them only invites tapping
                    // at a button that will never change.
                    child: rejected
                        ? const SizedBox.shrink()
                        : controller.showDocuments
                            ? documentsActions(controller)
                            : AuthPrimaryButton(
                                label: StringRes.checkVerificationStatus,
                                onPressed: controller.isChecking
                                    ? () {}
                                    : controller.checkStatusAPI,
                              ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget signOutPill(AccountVerificationController controller) {
    return Material(
      color: ColorRes.transparent,
      child: InkWell(
        onTap: controller.logoutAPI,
        borderRadius: BorderRadius.circular(24.getSize),
        child: Container(
          padding: EdgeInsets.symmetric(
            horizontal: 16.getSize,
            vertical: 10.getSize,
          ),
          decoration: BoxDecoration(
            color: ColorRes.surfaceColor,
            borderRadius: BorderRadius.circular(24.getSize),
            border: Border.all(color: ColorRes.borderColor),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.logout,
                size: 15.getSize,
                color: ColorRes.secondaryColor,
              ),
              8.widthSpacer,
              BaseTextDMSans(
                text: StringRes.logout,
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: ColorRes.secondaryColor,
              ).tr(),
            ],
          ),
        ),
      ),
    );
  }

  /// The violet (or red) rounded tile the screen opens with.
  Widget statusBadge(bool rejected) {
    return Container(
      height: 76.getSize,
      width: 76.getSize,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        gradient: rejected
            ? null
            : LinearGradient(
                colors: [ColorRes.primaryColorLight, ColorRes.primaryColorDark],
              ),
        color: rejected ? ColorRes.errorColor.withValues(alpha: 0.18) : null,
        borderRadius: BorderRadius.circular(24.getSize),
        border: rejected
            ? Border.all(color: ColorRes.errorColor.withValues(alpha: 0.4))
            : null,
      ),
      child: Icon(
        rejected ? Icons.cancel_outlined : Icons.hourglass_empty,
        size: 34.getSize,
        color: rejected ? ColorRes.errorColor : ColorRes.whiteColor,
      ),
    );
  }

  /// Created → under review → decided.
  Widget tracker(bool rejected) {
    return Container(
      padding: EdgeInsets.all(18.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceColor,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(color: ColorRes.borderColor),
      ),
      child: Column(
        children: [
          _TrackerStep(
            state: _StepState.done,
            title: tr(StringRes.verifyStepCreated),
            subtitle: tr(StringRes.verifyStepCreatedSub),
          ),
          _TrackerStep(
            state: rejected ? _StepState.done : _StepState.current,
            title: tr(StringRes.verifyStepReview),
            subtitle: rejected
                ? tr(StringRes.verifyStepReviewDoneSub)
                : tr(StringRes.verifyStepReviewSub),
          ),
          _TrackerStep(
            // A rejection is still an outcome, so the last step is
            // reached rather than left pending — it just is not a good
            // one.
            state: rejected ? _StepState.failed : _StepState.pending,
            title: rejected
                ? tr(StringRes.verifyStepRejected)
                : tr(StringRes.verifyStepApproved),
            subtitle: rejected
                ? tr(StringRes.verifyStepRejectedSub)
                : tr(StringRes.verifyStepApprovedSub),
            isLast: true,
          ),
        ],
      ),
    );
  }

  // ── Vendor app: "Documents submitted" ─────────────────────────────────────

  /// The vendor's pending screen, as a submitted-application result: icon,
  /// title, what happens now, one row per document with its status, and a
  /// note on what comes next. The title asks for documents instead while
  /// compulsory ones are still to upload.
  List<Widget> documentsResult(AccountVerificationController controller) {
    final toUpload = controller.hasDocumentsToUpload;
    final documents = controller.documents?.documents;

    return [
      Center(child: resultIcon(toUpload)),
      20.heightSpacer,
      BaseTextDMSans(
        key: const ValueKey('documentsResultTitle'),
        text: toUpload ? StringRes.uploadYourDocumentsTitle : StringRes.documentsSubmittedTitle,
        fontSize: 20,
        fontWeight: FontWeight.w700,
        color: ColorRes.secondaryColor,
        textAlign: TextAlign.center,
        maxLines: 2,
      ).tr(),
      10.heightSpacer,
      BaseTextDMSans(
        text: toUpload ? StringRes.uploadYourDocumentsDesc : StringRes.documentsSubmittedDesc,
        fontSize: 13,
        color: ColorRes.grayColor,
        textAlign: TextAlign.center,
        maxLines: 5,
      ).tr(),
      24.heightSpacer,
      // Rows appear once read; no spinner meanwhile (a failed read just
      // leaves them out, as the summary always has).
      if (documents != null)
        for (final document in documents) ...[
          documentRow(document),
          10.heightSpacer,
        ],
      8.heightSpacer,
      infoBox(),
    ];
  }

  Widget resultIcon(bool toUpload) {
    return Container(
      height: 84.getSize,
      width: 84.getSize,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(
          colors: [ColorRes.primaryColorLight, ColorRes.primaryColorDark],
        ),
        boxShadow: [
          BoxShadow(
            color: ColorRes.primaryColor.withValues(alpha: 0.25),
            blurRadius: 20.getSize,
            offset: Offset(0, 8.getSize),
          ),
        ],
      ),
      child: Icon(
        toUpload ? Icons.upload_file_outlined : Icons.task_alt,
        size: 38.getSize,
        color: ColorRes.whiteColor,
      ),
    );
  }

  /// One document: its name, its status in the status colour, and the
  /// matching icon — the result screen's per-check row.
  Widget documentRow(VendorDocumentModel document) {
    final color = VendorDocumentsView.statusColor(document.status);

    return Container(
      key: ValueKey('documentResultRow_${document.documentType.id}'),
      padding: EdgeInsets.symmetric(horizontal: 14.getSize, vertical: 12.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceColor,
        borderRadius: BorderRadius.circular(14.getSize),
        border: Border.all(color: ColorRes.borderColor),
      ),
      child: Row(
        children: [
          Container(
            height: 40.getSize,
            width: 40.getSize,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: ColorRes.primaryColor.withValues(alpha: 0.10),
              borderRadius: BorderRadius.circular(12.getSize),
              border: Border.all(color: ColorRes.primaryColor.withValues(alpha: 0.20)),
            ),
            child: Icon(Icons.description_outlined, size: 20.getSize, color: ColorRes.primaryColor),
          ),
          12.widthSpacer,
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                BaseTextDMSans(
                  text: document.documentType.name ?? '',
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: ColorRes.secondaryColor,
                  textAlign: TextAlign.start,
                  maxLines: 2,
                ),
                2.heightSpacer,
                BaseTextDMSans(
                  text: VendorDocumentsController.statusLabel(document.status),
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: color,
                  textAlign: TextAlign.start,
                  maxLines: 1,
                ).tr(),
              ],
            ),
          ),
          8.widthSpacer,
          Icon(statusIcon(document.status), size: 22.getSize, color: color),
        ],
      ),
    );
  }

  IconData statusIcon(String status) {
    switch (status) {
      case VendorDocumentModel.statusVerified:
        return Icons.check_circle;
      case VendorDocumentModel.statusUploaded:
      case VendorDocumentModel.statusInVerification:
        return Icons.hourglass_top_rounded;
      case VendorDocumentModel.statusReupload:
        return Icons.error_outline;
      case VendorDocumentModel.statusExpired:
        return Icons.event_busy_outlined;
      default:
        return Icons.radio_button_unchecked;
    }
  }

  Widget infoBox() {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(14.getSize),
      decoration: BoxDecoration(
        color: ColorRes.primaryColor.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(14.getSize),
        border: Border.all(color: ColorRes.primaryColor.withValues(alpha: 0.25)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.info_outline, size: 20.getSize, color: ColorRes.primaryColor),
          10.widthSpacer,
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                BaseTextDMSans(
                  text: StringRes.documentsSubmittedInfoTitle,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: ColorRes.primaryColor,
                  textAlign: TextAlign.start,
                ).tr(),
                4.heightSpacer,
                BaseTextDMSans(
                  text: StringRes.documentsSubmittedInfo,
                  fontSize: 12,
                  color: ColorRes.secondaryColor,
                  textAlign: TextAlign.start,
                  maxLines: 5,
                ).tr(),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// "Upload documents" (the stepper) while anything compulsory can still
  /// be uploaded, otherwise "View documents" (the list) — then the re-check.
  Widget documentsActions(AccountVerificationController controller) {
    final toUpload = controller.hasDocumentsToUpload;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        AuthPrimaryButton(
          key: ValueKey(toUpload ? 'uploadDocumentsAction' : 'viewDocumentsAction'),
          label: toUpload ? StringRes.uploadDocumentsAction : StringRes.viewDocumentsAction,
          onPressed: toUpload ? controller.openStepper : controller.openDocuments,
        ),
        10.heightSpacer,
        BaseRaisedButton(
          key: const ValueKey('checkStatusAction'),
          onPressed: controller.isChecking ? null : controller.checkStatusAPI,
          buttonText: StringRes.checkVerificationStatus,
          buttonColor: ColorRes.surfaceColor,
          textColor: ColorRes.primaryColor,
          borderColor: ColorRes.borderColor,
          fontWeight: FontWeight.w600,
        ),
      ],
    );
  }

  Widget reasonBox(String note) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ColorRes.errorColor.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(16.getSize),
        border: Border.all(color: ColorRes.errorColor.withValues(alpha: 0.35)),
      ),
      child: BaseTextDMSans(
        text: note,
        fontSize: 12,
        color: ColorRes.secondaryColor,
        textAlign: TextAlign.center,
        maxLines: 6,
      ),
    );
  }
}

enum _StepState { done, current, pending, failed }

/// One row of the tracker: a status dot, the connecting rail, and the
/// label pair.
class _TrackerStep extends StatelessWidget {
  const _TrackerStep({
    required this.state,
    required this.title,
    required this.subtitle,
    this.isLast = false,
  });

  final _StepState state;
  final String title;
  final String subtitle;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    final reached = state != _StepState.pending;

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Column(
            children: [
              dot(),
              if (!isLast)
                Expanded(
                  child: Container(
                    width: 2.getSize,
                    margin: EdgeInsets.symmetric(vertical: 4.getSize),
                    color: state == _StepState.done
                        ? ColorRes.primaryColor.withValues(alpha: 0.55)
                        : ColorRes.borderColor,
                  ),
                ),
            ],
          ),
          14.widthSpacer,
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: isLast ? 0 : 20.getSize),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  BaseTextDMSans(
                    text: title,
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color:
                        reached ? ColorRes.secondaryColor : ColorRes.grayColor,
                    textAlign: TextAlign.start,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  3.heightSpacer,
                  BaseTextDMSans(
                    text: subtitle,
                    fontSize: 12,
                    color: ColorRes.grayColor,
                    textAlign: TextAlign.start,
                    maxLines: 2,
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget dot() {
    final (Color background, Color border, IconData? icon, Color iconColor) =
        switch (state) {
      _StepState.done => (
          ColorRes.successColor.withValues(alpha: 0.18),
          ColorRes.successColor,
          Icons.check,
          ColorRes.successColor,
        ),
      _StepState.current => (
          ColorRes.primaryColor,
          ColorRes.primaryColor,
          Icons.schedule,
          ColorRes.whiteColor,
        ),
      _StepState.failed => (
          ColorRes.errorColor.withValues(alpha: 0.18),
          ColorRes.errorColor,
          Icons.close,
          ColorRes.errorColor,
        ),
      _StepState.pending => (
          ColorRes.transparent,
          ColorRes.borderColor,
          null,
          ColorRes.grayColor,
        ),
    };

    return Container(
      height: 28.getSize,
      width: 28.getSize,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: background,
        shape: BoxShape.circle,
        border: Border.all(color: border, width: 1.6),
      ),
      child:
          icon == null ? null : Icon(icon, size: 15.getSize, color: iconColor),
    );
  }
}
