import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator_platform_interface/geolocator_platform_interface.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/vendor_flow/add_vendor_module/add_vendor_controller.dart';

/// Stands in for the real plugin so tests control the permission outcome
/// without a device or platform channel — geolocator's static methods
/// delegate to whatever is set as [GeolocatorPlatform.instance].
class _DeniedGeolocator extends GeolocatorPlatform {
  @override
  Future<bool> isLocationServiceEnabled() async => true;

  @override
  Future<LocationPermission> checkPermission() async => LocationPermission.denied;

  @override
  Future<LocationPermission> requestPermission() async => LocationPermission.denied;
}

/// Captures the body sent to `POST /vendors/draft` instead of making a real
/// HTTP call, so the "draft still saves" claim can be checked directly
/// against what would go over the wire.
class _RecordingDataSource extends DataSource {
  Map<String, dynamic>? capturedDraftBody;

  @override
  Future<CommonResponse?> vendorDraftAPI({required Map<String, dynamic> body}) async {
    capturedDraftBody = body;

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'vendor': {'id': 42, 'business_name': body['business_name']},
        'resumed': false,
      },
      'error': null,
    }, statusCode: 201);
  }

  /// Every `POST /vendors/{id}/kyc` call — only ever the shop photo now.
  final List<Map<String, dynamic>> kycCalls = [];

  @override
  Future<CommonResponse?> vendorKycAPI({
    required int vendorId,
    required String shopPhotoPath,
  }) async {
    kycCalls.add({'vendorId': vendorId, 'shopPhotoPath': shopPhotoPath});
    return CommonResponse.fromJson({'success': true, 'data': {}, 'error': null}, statusCode: 200);
  }

  // A successful save now navigates to SelectPlanView, which fetches plans
  // on init. Stubbed so this test stays fully offline rather than depending
  // on real network timing/failure behaviour for an unrelated screen.
  @override
  Future<CommonResponse?> plansAPI() async {
    return CommonResponse.fromJson({
      'success': true,
      'data': <dynamic>[],
      'error': null,
    }, statusCode: 200);
  }
}

AddVendorController _filledController() {
  final controller = AddVendorController();
  controller.businessNameController.text = 'Cool Air';
  controller.ownerNameController.text = 'Bhavin';
  controller.phoneController.text = '9876543210';
  controller.emailController.text = 'vendor@example.com';
  return controller;
}

/// A real Form, so formKey.currentState.validate() (called inside
/// saveDraftAPI) has something to validate against.
Future<void> _pumpForm(WidgetTester tester, AddVendorController controller) {
  return tester.pumpWidget(
    GetMaterialApp(
      home: Scaffold(
        body: Form(
          key: controller.formKey,
          child: Column(
            children: [
              TextFormField(
                controller: controller.businessNameController,
                validator: (v) => controller.validateRequired(v, 'x'),
              ),
              TextFormField(
                controller: controller.ownerNameController,
                validator: (v) => controller.validateRequired(v, 'x'),
              ),
              TextFormField(
                controller: controller.phoneController,
                validator: controller.validatePhone,
              ),
              TextFormField(
                controller: controller.emailController,
                validator: controller.validateEmail,
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

void main() {
  setUp(() {
    GeolocatorPlatform.instance = _DeniedGeolocator();
  });

  testWidgets(
    'a denied location permission leaves lat/lng null and the draft still saves',
    (tester) async {
      final fakeDataSource = _RecordingDataSource();
      DataSource.instance = fakeDataSource;

      final controller = AddVendorController();
      controller.businessNameController.text = 'Cool Air';
      controller.ownerNameController.text = 'Bhavin';
      controller.phoneController.text = '9876543210';
      controller.emailController.text = 'vendor@example.com';

      // A real Form is needed so formKey.currentState.validate() (called
      // inside saveDraftAPI) has something to validate against.
      await tester.pumpWidget(
        GetMaterialApp(
          home: Scaffold(
            body: Form(
              key: controller.formKey,
              child: Column(
                children: [
                  TextFormField(
                    controller: controller.businessNameController,
                    validator: (v) => controller.validateRequired(v, 'x'),
                  ),
                  TextFormField(
                    controller: controller.ownerNameController,
                    validator: (v) => controller.validateRequired(v, 'x'),
                  ),
                  TextFormField(
                    controller: controller.phoneController,
                    validator: controller.validatePhone,
                  ),
                  TextFormField(
                    controller: controller.emailController,
                    validator: controller.validateEmail,
                  ),
                ],
              ),
            ),
          ),
        ),
      );

      await controller.captureLocation();

      expect(controller.latitude, isNull);
      expect(controller.longitude, isNull);
      expect(controller.hasLocation, isFalse);

      await controller.saveDraftAPI();
      await tester.pumpAndSettle();

      // The draft was created despite the denied permission.
      expect(controller.draftVendorId, 42);
      expect(fakeDataSource.capturedDraftBody, isNotNull);

      // And it went out with no lat/lng at all, matching the server's
      // 'nullable' rule rather than sending an explicit null.
      expect(fakeDataSource.capturedDraftBody!.containsKey('latitude'), isFalse);
      expect(fakeDataSource.capturedDraftBody!.containsKey('longitude'), isFalse);

      // No shop photo attached, so no KYC call at all.
      expect(fakeDataSource.kycCalls, isEmpty);

      controller.onClose();
    },
  );

  // Identity documents moved to the per-type KYC documents screen: this
  // step keeps only the shop photo (the public profile picture).
  testWidgets('the KYC step uploads only the shop photo, never an ID proof', (tester) async {
    final fakeDataSource = _RecordingDataSource();
    DataSource.instance = fakeDataSource;

    final controller = _filledController();
    controller.shopPhotoPath = 'shop.jpg';
    await _pumpForm(tester, controller);

    await controller.saveDraftAPI();
    await tester.pumpAndSettle();

    expect(fakeDataSource.capturedDraftBody!.containsKey('id_proof_type'), isFalse);
    expect(fakeDataSource.capturedDraftBody!.containsKey('id_proof'), isFalse);
    expect(fakeDataSource.kycCalls, [
      {'vendorId': 42, 'shopPhotoPath': 'shop.jpg'},
    ]);

    controller.onClose();
  });
}
