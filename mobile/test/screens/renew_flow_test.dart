import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/plan_model.dart';
import 'package:service_marketplace/common_model/subscription_model.dart';
import 'package:service_marketplace/common_model/vendor_me_model.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/salesman_vendor_detail_module/salesman_vendor_detail_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/select_services_module/select_services_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/subscription_confirmation_module/subscription_confirmation_controller.dart';
import 'package:service_marketplace/screens/vendor_flow/subscription_confirmation_module/subscription_confirmation_view.dart';

/// Records which endpoint the salesman flow called — renew or subscribe.
class _RecordingDataSource extends DataSource {
  String? calledEndpoint;
  Map<String, dynamic>? capturedBody;

  CommonResponse _created(Map<String, dynamic> body) => CommonResponse.fromJson({
        'success': true,
        'data': {
          'subscription': {
            'id': 99,
            'vendor_id': body['vendor_id'],
            'plan_id': body['plan_id'],
            'plan_name': 'Gold',
            'status': 'active',
            'price_paise': 249900,
            'payment': {'mode': body['payment_mode'], 'status': 'captured'},
          },
          'vendor': {'id': body['vendor_id'], 'status': 'active'},
        },
        'error': null,
      }, statusCode: 201);

  @override
  Future<CommonResponse?> renewSubscriptionAPI({
    required Map<String, dynamic> body,
    required String idempotencyKey,
  }) async {
    calledEndpoint = 'renew';
    capturedBody = body;
    return _created(body);
  }

  @override
  Future<CommonResponse?> subscribeAPI({
    required Map<String, dynamic> body,
    required String idempotencyKey,
  }) async {
    calledEndpoint = 'subscribe';
    capturedBody = body;
    return _created(body);
  }
}

SalesmanVendorDetailController _detail(Map<String, dynamic> vendor, {Map<String, dynamic>? activeSubscription}) {
  return SalesmanVendorDetailController(vendorId: 7)
    ..vendor = VendorMeModel.fromJson({'vendor': vendor, 'active_subscription': activeSubscription});
}

void main() {
  setUpAll(() {
    FlavorConfig.initialize(FlavorConfig.salesman);
  });

  tearDown(Get.reset);

  testWidgets('a salesman renewal calls renew, not subscribe, and shows "Plan renewed"', (tester) async {
    final fake = _RecordingDataSource();
    DataSource.instance = fake;

    final controller = SelectServicesController(
      vendorId: 7,
      plan: PlanModel(id: 5, name: 'Gold'),
      businessName: 'Cool Air',
      loginEmail: 'vendor@example.com',
      isRenewal: true,
    )
      ..selectedCategoryIds.add(1)
      ..selectedSubcategoryIds.add(10)
      ..selectedZoneIds.add(100);
    controller.selectPaymentMode('cash');

    await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
    await controller.subscribeAPI();
    await tester.pumpAndSettle();

    expect(fake.calledEndpoint, 'renew');
    expect(fake.capturedBody?['payment_mode'], 'cash');
    expect(fake.capturedBody?.containsKey('free_trial_days'), isFalse);

    final confirmation = Get.find<SubscriptionConfirmationController>();
    expect(confirmation.isRenewal, isTrue);
    // Paid in cash: no login details and no link — nothing to share.
    expect(confirmation.hasSomethingToShare, isFalse);
    expect(find.byType(SubscriptionConfirmationView), findsOneWidget);

    controller.onClose();
  });

  test('a renewal with a Razorpay link shares only the link', () {
    final confirmation = SubscriptionConfirmationController(
      businessName: 'Cool Air',
      loginEmail: 'vendor@example.com',
      temporaryPassword: '',
      isRenewal: true,
      subscription: SubscriptionModel.fromJson({
        'status': 'pending_payment',
        'payment': {'status': 'pending', 'payment_link_url': 'https://rzp.io/i/renew'},
      }),
    );

    expect(confirmation.hasSomethingToShare, isTrue);
    expect(confirmation.shareMessage, contains('https://rzp.io/i/renew'));
    expect(confirmation.shareMessage, isNot(contains('Temporary password')));
  });

  test('Renew is offered for lapsed vendors and in the last 15 days only', () {
    expect(_detail({'id': 7, 'status': 'expired'}).canRenew, isTrue);
    expect(_detail({'id': 7, 'status': 'grace'}).canRenew, isTrue);
    expect(_detail({'id': 7, 'status': 'active'}, activeSubscription: {'days_remaining': 12}).canRenew, isTrue);
    expect(_detail({'id': 7, 'status': 'active'}, activeSubscription: {'days_remaining': 200}).canRenew, isFalse);
    expect(_detail({'id': 7, 'status': 'draft'}).canRenew, isFalse);
  });
}
