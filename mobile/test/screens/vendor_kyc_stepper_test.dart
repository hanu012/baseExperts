import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/account_verification_module/account_verification_controller.dart';
import 'package:service_marketplace/screens/auth_flow/account_verification_module/account_verification_view.dart';
import 'package:service_marketplace/screens/vendor_flow/vendor_document_upload_module/vendor_document_upload_view.dart';
import 'package:service_marketplace/screens/vendor_flow/vendor_kyc_stepper_module/vendor_kyc_stepper_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/vendor_documents_module/vendor_documents_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/vendor_documents_module/vendor_documents_view.dart';
import 'package:service_marketplace/screens/vendor_flow/vendor_kyc_stepper_module/vendor_kyc_stepper_view.dart';
import 'package:service_marketplace/utils/socket_service.dart';

Map<String, dynamic> _type(
  int id,
  String name, {
  bool compulsory = true,
  bool hasNumber = false,
  bool maskNumber = false,
  bool hasBack = false,
  bool hasExpiry = false,
}) =>
    {
      'id': id,
      'code': 'type_$id',
      'name': name,
      'description': null,
      'has_number': hasNumber,
      'number_label': null,
      'number_hint': null,
      'mask_number': maskNumber,
      'has_expiry': hasExpiry,
      'has_back': hasBack,
      'is_compulsory': compulsory,
    };

Map<String, dynamic> _doc(
  Map<String, dynamic> type,
  String status, {
  int? id,
  String? reviewNote,
  bool? canUpload,
}) =>
    {
      'id': id ?? (status == 'pending_upload' ? null : 100 + (type['id'] as int)),
      'document_type': type,
      'status': status,
      'number': null,
      'expiry_date': null,
      'front_url': null,
      'back_url': null,
      'review_note': reviewNote,
      'uploaded_at': null,
      'can_upload': canUpload ?? !(status == 'verified' || status == 'in_verification'),
    };

/// Aadhaar sent back (re-upload), PAN verified, address proof already
/// uploaded, bank proof never uploaded (all compulsory), plus an optional
/// shop licence. Steps: Aadhaar, bank proof, review.
List<Map<String, dynamic>> _mixedDocuments() => [
      _doc(
        _type(1, 'Aadhaar Card', hasNumber: true, maskNumber: true, hasBack: true),
        'reupload',
        reviewNote: 'Photo is blurry, please retake',
      ),
      _doc(_type(2, 'PAN Card', hasNumber: true), 'verified'),
      _doc(_type(5, 'Address proof'), 'uploaded'),
      _doc(_type(4, 'Bank proof'), 'pending_upload'),
      _doc(_type(3, 'Shop licence', compulsory: false, hasExpiry: true), 'pending_upload'),
    ];

/// Every compulsory one already with the admin; the optional one not sent.
List<Map<String, dynamic>> _allSubmitted() => [
      _doc(_type(1, 'Aadhaar Card', hasNumber: true, maskNumber: true, hasBack: true), 'uploaded'),
      _doc(_type(2, 'PAN Card', hasNumber: true), 'in_verification'),
      _doc(_type(3, 'Shop licence', compulsory: false, hasExpiry: true), 'pending_upload'),
    ];

CommonResponse _ok(Map<String, dynamic> data) =>
    CommonResponse.fromJson({'success': true, 'data': data, 'error': null}, statusCode: 200);

/// A stand-in server holding the documents, so an upload changes what the
/// next read returns.
class _KycDataSource extends DataSource {
  _KycDataSource(this.documents);

  final List<Map<String, dynamic>> documents;
  final List<int?> listCalls = [];
  final List<Map<String, dynamic>> uploads = [];
  final List<int?> uploadVendorIds = [];

  /// Set to answer uploads with a 422 instead.
  Map<String, List<String>>? uploadFieldErrors;

  Map<String, dynamic> _envelope() => {
        'documents': documents,
        'missing_compulsory': [
          for (final d in documents)
            if ((d['document_type'] as Map)['is_compulsory'] == true && d['status'] != 'verified')
              (d['document_type'] as Map)['name'],
        ],
        'complete': false,
      };

  @override
  Future<CommonResponse?> vendorDocumentsAPI({int? vendorId}) async {
    listCalls.add(vendorId);
    return _ok(_envelope());
  }

  @override
  Future<CommonResponse?> uploadVendorDocumentAPI({
    int? vendorId,
    required Map<String, dynamic> fields,
    required String frontPath,
    String? backPath,
  }) async {
    uploads.add(fields);
    uploadVendorIds.add(vendorId);

    if (uploadFieldErrors != null) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {
          'code': 'VALIDATION_FAILED',
          'message': 'The given data was invalid.',
          'fields': uploadFieldErrors,
        },
      }, statusCode: 422);
    }

    final document = documents.firstWhere(
      (d) => (d['document_type'] as Map)['id'] == fields['document_type_id'],
    );
    document['status'] = 'uploaded';
    document['id'] ??= 200;
    document['review_note'] = null;

    return _ok(_envelope());
  }

  // The verification screen re-checks the account on its own.
  @override
  Future<CommonResponse?> userAPI() async => null;
}

String _tempImage(String name) {
  final dir = Directory.systemTemp.createTempSync('kyc_stepper_test');
  final file = File('${dir.path}/$name')..writeAsBytesSync(const []);
  return file.path;
}

String _stepLine(WidgetTester tester) =>
    tester.widget<Text>(find.byKey(const ValueKey('kycStepLine'))).textSpan!.toPlainText();

/// Pushes the stepper over a blank screen, the way its callers do, and
/// hands back its route result.
Future<Future<bool?>> _openStepper(
  WidgetTester tester, {
  int? vendorId,
  bool allowSkip = false,
}) async {
  await tester.pumpWidget(const GetMaterialApp(home: Scaffold()));

  final result = Get.to<bool?>(
    () => VendorKycStepperView(vendorId: vendorId, allowSkip: allowSkip),
  )!;
  await tester.pumpAndSettle();

  return result;
}

/// Fills in Aadhaar (masked number, two sides) on the current step.
Future<void> _fillAadhaar(WidgetTester tester, VendorKycStepperController controller) async {
  final upload = controller.uploadControllerFor(controller.currentDocument!);
  upload.frontPath = _tempImage('front.jpg');
  upload.backPath = _tempImage('back.jpg');
  upload.update();
  await tester.enterText(find.byKey(const ValueKey('documentNumber')), '1234');
  await tester.pumpAndSettle();
}

void _smallScreen(WidgetTester tester) {
  tester.view.devicePixelRatio = 1.0;
  tester.view.physicalSize = const Size(320, 568);
  addTearDown(() {
    tester.view.resetDevicePixelRatio();
    tester.view.resetPhysicalSize();
  });
}

void main() {
  setUpAll(() {
    FlavorConfig.initialize(FlavorConfig.vendor);
  });

  tearDown(Get.reset);

  group('VendorKycStepperView', () {
    testWidgets('steps are the uploadable compulsory documents, then review', (tester) async {
      final fake = _KycDataSource(_mixedDocuments());
      DataSource.instance = fake;

      await _openStepper(tester, vendorId: 42);

      final controller = Get.find<VendorKycStepperController>();
      expect(fake.listCalls, [42]);
      // Re-upload and pending upload only — not verified, not already
      // uploaded, not optional — in server order.
      expect(controller.steps.map((d) => d.documentType.id), [1, 4]);
      expect(controller.totalSteps, 3);
      expect(find.byKey(const ValueKey('kycProgress_2')), findsOneWidget);
      expect(find.byKey(const ValueKey('kycProgress_3')), findsNothing);
      expect(_stepLine(tester), contains('Aadhaar Card'));

      // The admin's reason leads the re-upload step.
      expect(find.text('Photo is blurry, please retake'), findsOneWidget);
      // The stepper's own button, not the upload screen's.
      expect(find.byKey(const ValueKey('submitDocument')), findsNothing);
      expect(find.byKey(const ValueKey('kycSaveContinue')), findsOneWidget);
      expect(find.byKey(const ValueKey('kycSkip')), findsNothing);
    });

    testWidgets('Save & continue uploads that document and moves on', (tester) async {
      final fake = _KycDataSource(_mixedDocuments());
      DataSource.instance = fake;

      await _openStepper(tester);
      final controller = Get.find<VendorKycStepperController>();

      // Nothing picked yet: the button does nothing.
      await tester.tap(find.byKey(const ValueKey('kycSaveContinue')));
      await tester.pumpAndSettle();
      expect(fake.uploads, isEmpty);

      await _fillAadhaar(tester, controller);
      await tester.tap(find.byKey(const ValueKey('kycSaveContinue')));
      await tester.pumpAndSettle();

      expect(fake.uploads, [
        {'document_type_id': 1, 'number': '1234'},
      ]);
      expect(fake.uploadVendorIds, [null]);
      expect(controller.currentIndex, 1);
      expect(_stepLine(tester), contains('Bank proof'));
      expect(controller.documents?.documents.first.status, 'uploaded');

      // Back arrow: the previous step, form still filled in.
      await tester.tap(find.byKey(const ValueKey('kycBack')));
      await tester.pumpAndSettle();
      expect(controller.currentIndex, 0);
      expect(
        controller.uploadControllerFor(controller.currentDocument!).numberController.text,
        '1234',
      );
    });

    testWidgets('a 422 keeps the step open with the field error', (tester) async {
      final fake = _KycDataSource(_mixedDocuments())
        ..uploadFieldErrors = {
          'number': ['Enter the last 4 digits only.'],
        };
      DataSource.instance = fake;

      await _openStepper(tester);
      final controller = Get.find<VendorKycStepperController>();

      await _fillAadhaar(tester, controller);
      await tester.tap(find.byKey(const ValueKey('kycSaveContinue')));
      await tester.pumpAndSettle();

      expect(fake.uploads, hasLength(1));
      expect(controller.currentIndex, 0);
      expect(find.text('Enter the last 4 digits only.'), findsOneWidget);
      expect(_stepLine(tester), contains('Aadhaar Card'));
    });

    testWidgets('with nothing compulsory to upload it is just the review step', (tester) async {
      DataSource.instance = _KycDataSource(_allSubmitted());

      await _openStepper(tester, vendorId: 42, allowSkip: true);
      final controller = Get.find<VendorKycStepperController>();

      expect(controller.steps, isEmpty);
      expect(controller.totalSteps, 1);
      expect(controller.isReviewStep, isTrue);
      expect(find.byKey(const ValueKey('kycSubmit')), findsOneWidget);
      expect(find.byKey(const ValueKey('kycSkip')), findsOneWidget);

      // Every document listed; "Add" only on the optional one.
      expect(find.byKey(const ValueKey('kycReviewRow_1')), findsOneWidget);
      expect(find.byKey(const ValueKey('kycReviewRow_2')), findsOneWidget);
      expect(find.byKey(const ValueKey('kycReviewRow_3')), findsOneWidget);
      expect(find.byKey(const ValueKey('kycOptionalUpload_3')), findsOneWidget);
      expect(find.byKey(const ValueKey('kycOptionalUpload_1')), findsNothing);

      // ... which opens the ordinary upload screen.
      await tester.tap(find.byKey(const ValueKey('kycOptionalUpload_3')));
      await tester.pumpAndSettle();
      expect(find.byType(VendorDocumentUploadView), findsOneWidget);
    });

    testWidgets('salesman: submit goes back with true', (tester) async {
      final fake = _KycDataSource(_mixedDocuments());
      DataSource.instance = fake;

      final result = await _openStepper(tester, vendorId: 42, allowSkip: true);
      final controller = Get.find<VendorKycStepperController>();

      await _fillAadhaar(tester, controller);
      await tester.tap(find.byKey(const ValueKey('kycSaveContinue')));
      await tester.pumpAndSettle();

      final bank = controller.uploadControllerFor(controller.currentDocument!);
      bank.frontPath = _tempImage('bank.jpg');
      bank.update();
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('kycSaveContinue')));
      await tester.pumpAndSettle();

      expect(fake.uploads.map((f) => f['document_type_id']), [1, 4]);
      expect(fake.uploadVendorIds, [42, 42]);
      expect(controller.isReviewStep, isTrue);
      expect(_stepLine(tester), contains('kycReviewStepTitle'));

      await tester.tap(find.byKey(const ValueKey('kycSubmit')));
      await tester.pumpAndSettle();

      expect(await result, isTrue);
      expect(find.byType(VendorKycStepperView), findsNothing);
    });

    testWidgets('salesman: Skip for now just leaves', (tester) async {
      DataSource.instance = _KycDataSource(_mixedDocuments());

      final result = await _openStepper(tester, vendorId: 42, allowSkip: true);

      await tester.tap(find.byKey(const ValueKey('kycSkip')));
      await tester.pumpAndSettle();

      expect(await result, isNull);
      expect(find.byType(VendorKycStepperView), findsNothing);
    });

    testWidgets('vendor: submit lands on the documents-submitted screen', (tester) async {
      DataSource.instance = _KycDataSource(_allSubmitted());

      await _openStepper(tester);
      await tester.tap(find.byKey(const ValueKey('kycSubmit')));
      await tester.pumpAndSettle();

      expect(find.byType(VendorKycStepperView), findsNothing);
      expect(find.byType(AccountVerificationView), findsOneWidget);
      expect(find.text('documentsSubmittedTitle'), findsOneWidget);
    });

    testWidgets('lays out without overflow on a 320x568 screen', (tester) async {
      DataSource.instance = _KycDataSource(_mixedDocuments());
      _smallScreen(tester);

      await tester.pumpWidget(
        const GetMaterialApp(home: VendorKycStepperView(vendorId: 42, allowSkip: true)),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);

      // The review step too.
      Get.find<VendorKycStepperController>().goToStep(2);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(find.byKey(const ValueKey('kycSubmit')), findsOneWidget);
    });
  });

  group('AccountVerificationView (vendor app)', () {
    testWidgets('opens the stepper by itself, once, while compulsory ones are missing',
        (tester) async {
      final fake = _KycDataSource(_mixedDocuments());
      DataSource.instance = fake;

      await tester.pumpWidget(const GetMaterialApp(home: AccountVerificationView()));
      await tester.pumpAndSettle();

      final controller = Get.find<AccountVerificationController>();
      expect(controller.stepperOpened, isTrue);
      expect(find.byType(VendorKycStepperView), findsOneWidget);

      // Backing out does not bounce them straight back in — not on the
      // re-read that follows, nor on a later one.
      await tester.tap(find.byKey(const ValueKey('kycBack')));
      await tester.pumpAndSettle();
      expect(find.byType(VendorKycStepperView), findsNothing);

      await controller.fetchDocumentsAPI();
      await tester.pumpAndSettle();
      expect(find.byType(VendorKycStepperView), findsNothing);

      // Pushed over the screen (never offAll): one read on landing, one
      // in the stepper, one on the way back, one by hand.
      expect(fake.listCalls, [null, null, null, null]);
      expect(find.byType(AccountVerificationView), findsOneWidget);
      expect(find.text('uploadYourDocumentsTitle'), findsOneWidget);
      expect(find.byKey(const ValueKey('uploadDocumentsAction')), findsOneWidget);
      expect(find.byKey(const ValueKey('viewDocumentsAction')), findsNothing);

      // The button opens it again.
      await tester.tap(find.byKey(const ValueKey('uploadDocumentsAction')));
      await tester.pumpAndSettle();
      expect(find.byType(VendorKycStepperView), findsOneWidget);
    });

    testWidgets('stepper submit comes back to it as "Documents submitted"', (tester) async {
      final fake = _KycDataSource(_mixedDocuments());
      DataSource.instance = fake;

      await tester.pumpWidget(const GetMaterialApp(home: AccountVerificationView()));
      await tester.pumpAndSettle();

      final stepper = Get.find<VendorKycStepperController>();
      await _fillAadhaar(tester, stepper);
      await tester.tap(find.byKey(const ValueKey('kycSaveContinue')));
      await tester.pumpAndSettle();
      final bank = stepper.uploadControllerFor(stepper.currentDocument!);
      bank.frontPath = _tempImage('bank.jpg');
      bank.update();
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('kycSaveContinue')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('kycSubmit')));
      await tester.pumpAndSettle();

      expect(find.byType(VendorKycStepperView), findsNothing);
      expect(find.byType(AccountVerificationView), findsOneWidget);
      expect(find.text('documentsSubmittedTitle'), findsOneWidget);
      expect(find.byKey(const ValueKey('viewDocumentsAction')), findsOneWidget);
    });

    testWidgets('does not open the stepper when every compulsory one is in', (tester) async {
      DataSource.instance = _KycDataSource(_allSubmitted());

      await tester.pumpWidget(const GetMaterialApp(home: AccountVerificationView()));
      await tester.pumpAndSettle();

      expect(Get.find<AccountVerificationController>().stepperOpened, isFalse);
      expect(find.byType(VendorKycStepperView), findsNothing);
      expect(find.text('documentsSubmittedTitle'), findsOneWidget);
      expect(find.byKey(const ValueKey('documentResultRow_1')), findsOneWidget);
      expect(find.byKey(const ValueKey('documentResultRow_3')), findsOneWidget);
      expect(find.byKey(const ValueKey('viewDocumentsAction')), findsOneWidget);
      expect(find.byKey(const ValueKey('uploadDocumentsAction')), findsNothing);
      expect(find.byKey(const ValueKey('checkStatusAction')), findsOneWidget);
    });

    testWidgets('the documents-submitted screen fits a 320x568 screen', (tester) async {
      DataSource.instance = _KycDataSource(_allSubmitted());
      _smallScreen(tester);

      await tester.pumpWidget(const GetMaterialApp(home: AccountVerificationView()));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
    });
  });

  group('kyc.document over the socket', () {
    // Get.reset() between tests drops controllers without onClose, so a
    // listener left by an earlier test's screen is cleared here first.
    setUp(() {
      for (final id in [
        'AccountVerificationController',
        'VendorDocumentsController',
        'VendorKycStepperController',
      ]) {
        SocketService.instance.off('kyc.document', id);
      }
    });

    /// What Reverb delivers when admin changes one of the vendor's documents.
    void push() => SocketService.instance.handleMessage(jsonEncode({
          'event': 'kyc.document',
          'channel': 'private-App.Models.User.7',
          'data': jsonEncode({'document_id': 12, 'document_type_id': 1, 'status': 'verified'}),
        }));

    testWidgets('re-reads the documents on the verification screen, until it is gone',
        (tester) async {
      final fake = _KycDataSource(_allSubmitted());
      DataSource.instance = fake;

      await tester.pumpWidget(const GetMaterialApp(home: AccountVerificationView()));
      await tester.pumpAndSettle();
      expect(fake.listCalls, [null]);

      // Admin asks for a re-upload: the screen hears it and re-reads — and,
      // a compulsory one now needing upload, opens the stepper (whose own
      // read is the third).
      fake.documents.first['status'] = 'reupload';
      push();
      await tester.pumpAndSettle();
      expect(fake.listCalls, [null, null, null]);
      expect(Get.find<AccountVerificationController>().hasDocumentsToUpload, isTrue);
      expect(find.byType(VendorKycStepperView), findsOneWidget);

      // Screen gone (controller deleted): a later push does nothing.
      Get.back();
      await tester.pumpWidget(const GetMaterialApp(home: Scaffold()));
      await tester.pumpAndSettle();
      expect(Get.isRegistered<AccountVerificationController>(), isFalse);
      final calls = fake.listCalls.length;
      push();
      await tester.pumpAndSettle();
      expect(fake.listCalls, hasLength(calls));
    });

    testWidgets("re-reads the vendor's own documents list, until it is gone", (tester) async {
      final fake = _KycDataSource(_allSubmitted());
      DataSource.instance = fake;

      await tester.pumpWidget(const GetMaterialApp(home: VendorDocumentsView()));
      await tester.pumpAndSettle();
      expect(fake.listCalls, [null]);

      fake.documents[1]['status'] = 'verified';
      push();
      await tester.pumpAndSettle();
      expect(fake.listCalls, [null, null]);
      expect(Get.find<VendorDocumentsController>().documents?.documents[1].status, 'verified');

      await tester.pumpWidget(const GetMaterialApp(home: Scaffold()));
      await tester.pumpAndSettle();
      expect(Get.isRegistered<VendorDocumentsController>(), isFalse);
      push();
      await tester.pumpAndSettle();
      expect(fake.listCalls, [null, null]);
    });

    testWidgets("a salesman's view of a vendor does not listen", (tester) async {
      final fake = _KycDataSource(_allSubmitted());
      DataSource.instance = fake;

      await tester.pumpWidget(const GetMaterialApp(home: VendorDocumentsView(vendorId: 42)));
      await tester.pumpAndSettle();
      push();
      await tester.pumpAndSettle();

      expect(fake.listCalls, [42]);
    });

    testWidgets('the stepper refreshes statuses without losing the current step', (tester) async {
      final fake = _KycDataSource(_mixedDocuments());
      DataSource.instance = fake;

      await _openStepper(tester);
      final controller = Get.find<VendorKycStepperController>();
      await _fillAadhaar(tester, controller);
      expect(fake.listCalls, [null]);

      fake.documents[2]['status'] = 'verified';
      push();
      await tester.pumpAndSettle();

      expect(fake.listCalls, [null, null]);
      expect(controller.documents?.documents[2].status, 'verified');
      expect(controller.currentIndex, 0);
      expect(controller.steps.map((d) => d.documentType.id), [1, 4]);
      final upload = controller.uploadControllerFor(controller.currentDocument!);
      expect(upload.numberController.text, '1234');
      expect(upload.frontPath, isNotNull);
      expect(upload.backPath, isNotNull);
    });
  });
}
