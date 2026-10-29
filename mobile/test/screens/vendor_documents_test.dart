import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/vendor_document_model.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/account_verification_module/account_verification_controller.dart';
import 'package:service_marketplace/screens/auth_flow/account_verification_module/account_verification_view.dart';
import 'package:service_marketplace/screens/vendor_flow/vendor_document_upload_module/vendor_document_upload_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/vendor_document_upload_module/vendor_document_upload_view.dart';
import 'package:service_marketplace/screens/vendor_flow/vendor_documents_module/vendor_documents_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/vendor_documents_module/vendor_documents_view.dart';

Map<String, dynamic> _aadhaarType() => {
      'id': 1,
      'code': 'aadhaar',
      'name': 'Aadhaar Card',
      'description': 'Front and back of the card',
      'has_number': true,
      'number_label': 'Last 4 digits of Aadhaar',
      'number_hint': '1234',
      'mask_number': true,
      'has_expiry': false,
      'has_back': true,
      'is_compulsory': true,
    };

Map<String, dynamic> _panType() => {
      'id': 2,
      'code': 'pan',
      'name': 'PAN Card',
      'description': null,
      'has_number': true,
      'number_label': 'PAN number',
      'number_hint': null,
      'mask_number': false,
      'has_expiry': false,
      'has_back': false,
      'is_compulsory': true,
    };

Map<String, dynamic> _shopLicenceType() => {
      'id': 3,
      'code': 'shop_licence',
      'name': 'Shop licence',
      'description': null,
      'has_number': false,
      'number_label': null,
      'number_hint': null,
      'mask_number': false,
      'has_expiry': true,
      'has_back': false,
      'is_compulsory': false,
    };

/// Aadhaar sent back for a re-upload, PAN verified (locked), the optional
/// licence never uploaded.
Map<String, dynamic> _listData() => {
      'documents': [
        {
          'id': 12,
          'document_type': _aadhaarType(),
          'status': 'reupload',
          'number': 'XXXX XXXX 1234',
          'expiry_date': null,
          'front_url': null,
          'back_url': null,
          'review_note': 'Photo is blurry, please retake',
          'uploaded_at': '2026-10-20T10:00:00Z',
          'can_upload': true,
        },
        {
          'id': 13,
          'document_type': _panType(),
          'status': 'verified',
          'number': 'ABCDE1234F',
          'expiry_date': null,
          'front_url': null,
          'back_url': null,
          'review_note': null,
          'uploaded_at': '2026-10-20T10:00:00Z',
          'can_upload': false,
        },
        {
          'id': null,
          'document_type': _shopLicenceType(),
          'status': 'pending_upload',
          'number': null,
          'expiry_date': null,
          'front_url': null,
          'back_url': null,
          'review_note': null,
          'uploaded_at': null,
          'can_upload': true,
        },
      ],
      'missing_compulsory': ['Aadhaar Card'],
      'complete': false,
    };

CommonResponse _ok(Map<String, dynamic> data) =>
    CommonResponse.fromJson({'success': true, 'data': data, 'error': null}, statusCode: 200);

/// Records what would go over the wire instead of making HTTP calls.
class _DocumentsDataSource extends DataSource {
  final List<int?> listCalls = [];

  Map<String, dynamic>? uploadedFields;
  int? uploadedVendorId;
  String? uploadedFront;
  String? uploadedBack;
  bool uploadCalled = false;

  /// Set to answer the upload with a 422 instead.
  Map<String, List<String>>? uploadFieldErrors;

  @override
  Future<CommonResponse?> vendorDocumentsAPI({int? vendorId}) async {
    listCalls.add(vendorId);
    return _ok(_listData());
  }

  @override
  Future<CommonResponse?> uploadVendorDocumentAPI({
    int? vendorId,
    required Map<String, dynamic> fields,
    required String frontPath,
    String? backPath,
  }) async {
    uploadCalled = true;
    uploadedVendorId = vendorId;
    uploadedFields = fields;
    uploadedFront = frontPath;
    uploadedBack = backPath;

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

    final data = _listData();
    (data['documents'] as List)[0]['status'] = 'uploaded';
    return _ok(data);
  }

  // The verification screen re-checks the account on its own.
  @override
  Future<CommonResponse?> userAPI() async => null;
}

VendorDocumentModel _document(Map<String, dynamic> type, {String status = 'pending_upload'}) =>
    VendorDocumentModel.fromJson({
      'id': null,
      'document_type': type,
      'status': status,
      'can_upload': true,
    });

/// A real (empty) file, so the thumbnail's Image.file has something to
/// open — it fails to decode and falls back to its icon.
String _tempImage(String name) {
  final dir = Directory.systemTemp.createTempSync('kyc_test');
  final file = File('${dir.path}/$name')..writeAsBytesSync(const []);
  return file.path;
}

/// Opens the upload form the way the list does — pushed, with a result —
/// and returns the controller plus the pending route result.
Future<(VendorDocumentUploadController, Future<VendorDocumentsModel?>)> _openUpload(
  WidgetTester tester,
  VendorDocumentModel document, {
  int? vendorId,
}) async {
  await tester.pumpWidget(const GetMaterialApp(home: Scaffold()));

  final result = Get.to<VendorDocumentsModel?>(
    () => VendorDocumentUploadView(vendorId: vendorId, document: document),
  )!;
  await tester.pumpAndSettle();

  return (Get.find<VendorDocumentUploadController>(), result);
}

void main() {
  setUpAll(() {
    FlavorConfig.initialize(FlavorConfig.vendor);
  });

  tearDown(Get.reset);

  group('VendorDocumentsModel', () {
    test('parses documents, missing compulsory names and completeness', () {
      final model = VendorDocumentsModel.fromJson(_listData());

      expect(model.documents, hasLength(3));
      expect(model.missingCompulsory, ['Aadhaar Card']);
      expect(model.complete, isFalse);

      final aadhaar = model.documents.first;
      expect(aadhaar.id, 12);
      expect(aadhaar.documentType.maskNumber, isTrue);
      expect(aadhaar.documentType.hasBack, isTrue);
      expect(aadhaar.reviewNote, 'Photo is blurry, please retake');

      expect(model.documents[2].id, isNull);
      expect(model.documents[2].documentType.hasExpiry, isTrue);

      // Re-upload is not "submitted"; verified PAN is.
      expect(model.compulsoryCount, 2);
      expect(model.compulsorySubmittedCount, 1);
    });
  });

  group('VendorDocumentsView', () {
    testWidgets('lists every document with the missing banner and review note', (tester) async {
      final fake = _DocumentsDataSource();
      DataSource.instance = fake;

      await tester.pumpWidget(const GetMaterialApp(home: VendorDocumentsView(vendorId: 42)));
      await tester.pumpAndSettle();

      // Salesman path: scoped to the vendor.
      expect(fake.listCalls, [42]);

      final controller = Get.find<VendorDocumentsController>();
      expect(controller.documents?.documents, hasLength(3));
      expect(controller.showMissingBanner, isTrue);

      expect(find.byKey(const ValueKey('documentsBanner')), findsOneWidget);
      expect(find.textContaining('Aadhaar Card'), findsWidgets);
      expect(find.text('Photo is blurry, please retake'), findsOneWidget);

      // can_upload false (verified PAN) → no Upload; the others have one.
      expect(find.byKey(const ValueKey('uploadDocument_1')), findsOneWidget);
      expect(find.byKey(const ValueKey('uploadDocument_2')), findsNothing);
      expect(find.byKey(const ValueKey('uploadDocument_3')), findsOneWidget);
    });

    testWidgets('vendor app reads its own documents (no vendor id)', (tester) async {
      final fake = _DocumentsDataSource();
      DataSource.instance = fake;

      await tester.pumpWidget(const GetMaterialApp(home: VendorDocumentsView()));
      await tester.pumpAndSettle();

      expect(fake.listCalls, [null]);
    });

    testWidgets('lays out without overflow on a 320x568 screen', (tester) async {
      DataSource.instance = _DocumentsDataSource();
      tester.view.devicePixelRatio = 1.0;
      tester.view.physicalSize = const Size(320, 568);
      addTearDown(() {
        tester.view.resetDevicePixelRatio();
        tester.view.resetPhysicalSize();
      });

      await tester.pumpWidget(const GetMaterialApp(home: VendorDocumentsView()));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
    });
  });

  testWidgets('upload form lays out without overflow on a 320x568 screen', (tester) async {
    DataSource.instance = _DocumentsDataSource();
    tester.view.devicePixelRatio = 1.0;
    tester.view.physicalSize = const Size(320, 568);
    addTearDown(() {
      tester.view.resetDevicePixelRatio();
      tester.view.resetPhysicalSize();
    });

    await tester.pumpWidget(GetMaterialApp(
      home: VendorDocumentUploadView(
        document: VendorDocumentModel.fromJson(
          (_listData()['documents'] as List).first as Map<String, dynamic>,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    // Number field present for Aadhaar; no expiry for it.
    expect(find.byKey(const ValueKey('documentNumber')), findsOneWidget);
    expect(find.byKey(const ValueKey('documentBack')), findsOneWidget);
    expect(find.byKey(const ValueKey('documentExpiry')), findsNothing);
  });

  group('VendorDocumentUploadController', () {
    testWidgets('a type without a number sends no number, and the expiry date', (tester) async {
      final fake = _DocumentsDataSource();
      DataSource.instance = fake;

      final (controller, result) = await _openUpload(
        tester,
        _document(_shopLicenceType()),
        vendorId: 42,
      );

      expect(controller.canSubmit, isFalse);

      controller.frontPath = _tempImage('front.jpg');
      controller.update();
      // Expiry is required for this type.
      expect(controller.canSubmit, isFalse);

      controller.setExpiryDate(DateTime(2031, 1, 31));
      expect(controller.canSubmit, isTrue);

      await controller.submitAPI();
      await tester.pumpAndSettle();

      expect(fake.uploadedVendorId, 42);
      expect(fake.uploadedFields, {'document_type_id': 3, 'expiry_date': '2031-01-31'});
      expect(fake.uploadedFields!.containsKey('number'), isFalse);
      expect(fake.uploadedFront, controller.frontPath);
      expect(fake.uploadedBack, isNull);

      // The refreshed list goes back to the documents screen.
      final refreshed = await result;
      expect(refreshed?.documents.first.status, VendorDocumentModel.statusUploaded);
    });

    testWidgets('a masked number needs exactly 4 digits and a back photo', (tester) async {
      final fake = _DocumentsDataSource();
      DataSource.instance = fake;

      final (controller, result) = await _openUpload(tester, _document(_aadhaarType()));

      controller.frontPath = _tempImage('front.jpg');
      controller.numberController.text = '1234';
      controller.update();
      // Two-sided: no back yet.
      expect(controller.canSubmit, isFalse);

      controller.backPath = _tempImage('back.jpg');
      controller.update();
      expect(controller.canSubmit, isTrue);

      for (final bad in ['123', '12345', '12a4', '']) {
        controller.numberController.text = bad;
        expect(controller.canSubmit, isFalse, reason: 'accepted "$bad"');
      }

      // The field itself refuses anything past four digits.
      await tester.enterText(find.byKey(const ValueKey('documentNumber')), '98765ab');
      expect(controller.numberController.text, '9876');

      await controller.submitAPI();
      await tester.pumpAndSettle();

      expect(fake.uploadedFields, {'document_type_id': 1, 'number': '9876'});
      expect(fake.uploadedBack, controller.backPath);
      expect(await result, isNotNull);
    });

    testWidgets('a 422 marks the field and stays on the form', (tester) async {
      final fake = _DocumentsDataSource()
        ..uploadFieldErrors = {
          'number': ['The PAN number format is invalid.'],
        };
      DataSource.instance = fake;

      final (controller, _) = await _openUpload(tester, _document(_panType()));

      controller.frontPath = _tempImage('front.jpg');
      controller.numberController.text = 'ABC';
      controller.update();

      await controller.submitAPI();
      await tester.pumpAndSettle();

      expect(fake.uploadedFields?['number'], 'ABC');
      expect(controller.serverErrors['number'], 'The PAN number format is invalid.');
      expect(find.text('The PAN number format is invalid.'), findsOneWidget);
      expect(find.byType(VendorDocumentUploadView), findsOneWidget);
    });
  });

  group('AccountVerificationView (vendor app)', () {
    testWidgets('offers Upload documents with the missing summary', (tester) async {
      final fake = _DocumentsDataSource();
      DataSource.instance = fake;

      await tester.pumpWidget(const GetMaterialApp(home: AccountVerificationView()));
      await tester.pumpAndSettle();

      // Aadhaar still needs a re-upload, so the stepper opened over it by
      // itself (its own read is the second call); back out to the screen.
      expect(fake.listCalls, [null, null]);
      Get.back();
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('uploadDocumentsAction')), findsOneWidget);
      expect(
        Get.find<AccountVerificationController>().documents?.missingCompulsory,
        ['Aadhaar Card'],
      );
    });
  });
}
