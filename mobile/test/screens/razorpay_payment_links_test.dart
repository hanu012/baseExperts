import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/subscription_model.dart';
import 'package:service_marketplace/common_model/vendor_me_model.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_landing_module/vendor_landing_view.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_payment_pending_module/vendor_payment_pending_view.dart';
import 'package:service_marketplace/screens/vendor_flow/subscription_confirmation_module/subscription_confirmation_controller.dart';

Map<String, dynamic> _subscriptionJson({String status = 'pending_payment', String paymentStatus = 'pending', String? link}) => {
      'id': 5,
      'plan_name': 'Gold',
      'status': status,
      'price_paise': 99900,
      'payment': {
        'mode': 'online',
        'amount_paise': 99900,
        'status': paymentStatus,
        'payment_link_url': link,
        'payment_link_expires_at': '2026-10-15T12:00:00+00:00',
      },
    };

class _VendorMeDataSource extends DataSource {
  _VendorMeDataSource(this.vendor);

  final Map<String, dynamic> vendor;

  @override
  Future<CommonResponse?> vendorMeAPI() async => CommonResponse.fromJson({
        'success': true,
        'data': {'vendor': vendor, 'active_subscription': null},
        'error': null,
      });
}

void main() {
  setUpAll(() {
    FlavorConfig.initialize(FlavorConfig.vendor);
  });

  tearDown(Get.reset);

  test('a subscription with an unpaid link reads as awaiting payment', () {
    final pending = SubscriptionModel.fromJson(_subscriptionJson(link: 'https://rzp.io/i/x'));
    expect(pending.isAwaitingPayment, isTrue);
    expect(pending.paymentLinkUrl, 'https://rzp.io/i/x');

    // Razorpay off: the placeholder captures immediately.
    final paid = SubscriptionModel.fromJson(_subscriptionJson(status: 'active', paymentStatus: 'captured'));
    expect(paid.isAwaitingPayment, isFalse);
    expect(paid.paymentLinkUrl, isNull);
  });

  test('the vendor profile carries a pending payment', () {
    final vendorMe = VendorMeModel.fromJson({
      'vendor': {
        'id': 3,
        'has_active_subscription': false,
        'pending_payment': {'plan_name': 'Gold', 'amount_paise': 99900, 'expires_at': null},
      },
    });

    expect(vendorMe.pendingPayment?.planName, 'Gold');
    expect(vendorMe.pendingPayment?.amountPaise, 99900);
  });

  testWidgets('landing sends a vendor with an unpaid link to "Waiting for payment"', (tester) async {
    DataSource.instance = _VendorMeDataSource({
      'id': 3,
      'has_active_subscription': false,
      'pending_payment': {'plan_name': 'Gold', 'amount_paise': 99900, 'expires_at': null},
    });

    await tester.pumpWidget(const GetMaterialApp(home: VendorLandingView()));
    await tester.pumpAndSettle();

    expect(find.byType(VendorPaymentPendingView), findsOneWidget);
  });

  test('the salesman share message includes the payment link only when there is one', () {
    final withLink = SubscriptionConfirmationController(
      businessName: 'Cool Air',
      loginEmail: 'v@example.com',
      temporaryPassword: 'Temp123',
      subscription: SubscriptionModel.fromJson(_subscriptionJson(link: 'https://rzp.io/i/x')),
    );
    expect(withLink.isAwaitingPayment, isTrue);
    expect(withLink.shareMessage, contains('https://rzp.io/i/x'));

    final placeholder = SubscriptionConfirmationController(
      businessName: 'Cool Air',
      loginEmail: 'v@example.com',
      temporaryPassword: 'Temp123',
      subscription: SubscriptionModel.fromJson(_subscriptionJson(status: 'active', paymentStatus: 'captured')),
    );
    expect(placeholder.isAwaitingPayment, isFalse);
    expect(placeholder.shareMessage, isNot(contains('Pay here')));
  });
}
