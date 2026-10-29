import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/plan_model.dart';
import 'package:service_marketplace/common_model/subscription_model.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/vendor_flow/select_services_module/select_services_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/subscription_confirmation_module/subscription_confirmation_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/subscription_confirmation_module/subscription_confirmation_view.dart';

/// Captures what would go out over the wire to POST /api/subscriptions,
/// instead of making a real HTTP call.
class _RecordingSubscribeDataSource extends DataSource {
  Map<String, dynamic>? capturedBody;
  String? capturedIdempotencyKey;
  bool succeeds = true;
  int settingsFreeTrialMaxDays = 15;

  @override
  Future<CommonResponse?> categoriesAPI() async {
    return CommonResponse.fromJson({'success': true, 'data': <dynamic>[], 'error': null});
  }

  @override
  Future<CommonResponse?> zonesAPI() async {
    return CommonResponse.fromJson({'success': true, 'data': <dynamic>[], 'error': null});
  }

  @override
  Future<CommonResponse?> settingsAPI() async {
    return CommonResponse.fromJson({
      'success': true,
      'data': {'free_trial_max_days': settingsFreeTrialMaxDays},
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> subscribeAPI({
    required Map<String, dynamic> body,
    required String idempotencyKey,
  }) async {
    capturedBody = body;
    capturedIdempotencyKey = idempotencyKey;

    if (!succeeds) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {
          'code': 'VALIDATION_FAILED',
          'message': 'The given data was invalid.',
          'fields': {
            'zone_ids': ['This plan allows at most 2 zones.'],
          },
        },
      }, statusCode: 422);
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'subscription': {
          'id': 99,
          'vendor_id': body['vendor_id'],
          'plan_id': body['plan_id'],
          'plan_name': 'Gold',
          'source': 'salesman',
          'status': 'active',
          'start_date': '2026-08-20',
          'end_date': '2027-08-20',
          'price_paise': 249900,
        },
        'vendor': {'id': body['vendor_id'], 'status': 'active'},
        'temporary_password': 'TempPass123',
      },
      'error': null,
    }, statusCode: 201);
  }
}

// UUID v4 shape: 8-4-4-4-12 hex digits, version nibble 4.
final _uuidV4Pattern = RegExp(
  r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
  caseSensitive: false,
);

SelectServicesController _buildController() {
  final plan = PlanModel(id: 5, name: 'Gold');

  final controller = SelectServicesController(
    vendorId: 7,
    plan: plan,
    businessName: 'Cool Air',
    loginEmail: 'vendor@example.com',
  );
  controller.selectedCategoryIds.add(1);
  controller.selectedSubcategoryIds.add(10);
  controller.selectedZoneIds.add(100);

  return controller;
}

void main() {
  testWidgets(
    'subscribeAPI sends the selected payment mode and a valid Idempotency-Key, then navigates to the confirmation screen',
    (tester) async {
      final fake = _RecordingSubscribeDataSource();
      DataSource.instance = fake;

      final controller = _buildController();
      controller.selectPaymentMode('online');

      await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));

      await controller.subscribeAPI();
      await tester.pumpAndSettle();

      expect(fake.capturedBody?['vendor_id'], 7);
      expect(fake.capturedBody?['plan_id'], 5);
      expect(fake.capturedBody?['payment_mode'], 'online');
      expect(fake.capturedBody?['category_ids'], [1]);
      expect(fake.capturedBody?['subcategory_ids'], [10]);
      expect(fake.capturedBody?['zone_ids'], [100]);

      expect(fake.capturedIdempotencyKey, isNotNull);
      expect(_uuidV4Pattern.hasMatch(fake.capturedIdempotencyKey!), isTrue);

      expect(find.byType(SubscriptionConfirmationView), findsOneWidget);
      expect(find.text('TempPass123'), findsOneWidget);

      controller.onClose();
    },
  );

  testWidgets('defaults to cash when no payment mode was explicitly chosen', (tester) async {
    final fake = _RecordingSubscribeDataSource();
    DataSource.instance = fake;

    final controller = _buildController();

    await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
    await controller.subscribeAPI();
    await tester.pumpAndSettle();

    expect(fake.capturedBody?['payment_mode'], 'cash');

    controller.onClose();
  });

  testWidgets(
    'a server-side rejection does not navigate, and resets isSubmitting',
    (tester) async {
      final fake = _RecordingSubscribeDataSource()..succeeds = false;
      DataSource.instance = fake;

      final controller = _buildController();

      await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
      await controller.subscribeAPI();
      await tester.pumpAndSettle();

      expect(find.byType(SubscriptionConfirmationView), findsNothing);
      expect(controller.isSubmitting, isFalse);

      controller.onClose();
    },
  );

  testWidgets(
    'a free-trial submission sends payment_mode=free with the chosen duration',
    (tester) async {
      final fake = _RecordingSubscribeDataSource();
      DataSource.instance = fake;

      final controller = _buildController();
      controller.selectPaymentMode('free');
      controller.setFreeTrialDays(10);

      await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
      await controller.subscribeAPI();
      await tester.pumpAndSettle();

      expect(fake.capturedBody?['payment_mode'], 'free');
      expect(fake.capturedBody?['free_trial_days'], 10);

      controller.onClose();
    },
  );

  testWidgets(
    'cash and online omit free_trial_days entirely, not as an explicit null',
    (tester) async {
      final fake = _RecordingSubscribeDataSource();
      DataSource.instance = fake;

      final controller = _buildController();
      controller.selectPaymentMode('cash');

      await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
      await controller.subscribeAPI();
      await tester.pumpAndSettle();

      expect(fake.capturedBody!.containsKey('free_trial_days'), isFalse);

      controller.onClose();
    },
  );

  testWidgets(
    'a free_trial_days field error from the server surfaces without crashing',
    (tester) async {
      final fake = _RecordingSubscribeDataSource()..succeeds = false;
      DataSource.instance = fake;

      final controller = _buildController();
      controller.selectPaymentMode('free');
      controller.setFreeTrialDays(30); // over-cap on purpose

      await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
      await controller.subscribeAPI();
      await tester.pumpAndSettle();

      expect(find.byType(SubscriptionConfirmationView), findsNothing);
      expect(controller.isSubmitting, isFalse);

      controller.onClose();
    },
  );

  testWidgets('setFreeTrialDays clamps to [1, freeTrialMaxDays]', (tester) async {
    final controller = _buildController();
    controller.freeTrialMaxDays = 15;

    controller.setFreeTrialDays(0);
    expect(controller.freeTrialDays, 1);

    controller.setFreeTrialDays(999);
    expect(controller.freeTrialDays, 15);

    controller.onClose();
  });

  testWidgets(
    'defaults to a 7-day trial, not the max — reaching the cap takes a deliberate adjustment',
    (tester) async {
      final controller = _buildController();
      expect(controller.freeTrialDays, 7);
      controller.onClose();
    },
  );

  testWidgets(
    'fetchMasterDataAPI reads the live free_trial_max_days and clamps the default duration to it',
    (tester) async {
      final fake = _RecordingSubscribeDataSource()..settingsFreeTrialMaxDays = 5;
      DataSource.instance = fake;

      final controller = _buildController();

      await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
      await controller.fetchMasterDataAPI();
      await tester.pumpAndSettle();

      expect(controller.freeTrialMaxDays, 5);
      // The 7-day default no longer fits under a 5-day cap.
      expect(controller.freeTrialDays, 5);

      controller.onClose();
    },
  );

  testWidgets(
    'refreshFreeTrialCapAPI picks up a cap the admin changed after the screen opened',
    (tester) async {
      final fake = _RecordingSubscribeDataSource()..settingsFreeTrialMaxDays = 15;
      DataSource.instance = fake;

      final controller = _buildController();

      await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
      await controller.fetchMasterDataAPI();
      expect(controller.freeTrialMaxDays, 15);

      // Admin raises the cap mid-flow; the next sheet open must see it.
      fake.settingsFreeTrialMaxDays = 20;
      await controller.refreshFreeTrialCapAPI();
      expect(controller.freeTrialMaxDays, 20);

      // And a lowered cap pulls an already-chosen duration down with it.
      controller.setFreeTrialDays(18);
      fake.settingsFreeTrialMaxDays = 10;
      await controller.refreshFreeTrialCapAPI();
      expect(controller.freeTrialMaxDays, 10);
      expect(controller.freeTrialDays, 10);

      controller.onClose();
    },
  );

  group('SubscriptionConfirmationController', () {
    test('the share message carries the login email and temp password', () {
      final controller = SubscriptionConfirmationController(
        businessName: 'Cool Air',
        loginEmail: 'vendor@example.com',
        temporaryPassword: 'TempPass123',
        subscription: SubscriptionModel(),
      );

      expect(controller.shareMessage, contains('Cool Air'));
      expect(controller.shareMessage, contains('vendor@example.com'));
      expect(controller.shareMessage, contains('TempPass123'));
    });
  });
}
