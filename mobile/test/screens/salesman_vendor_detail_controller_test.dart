import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/salesman_vendor_detail_module/salesman_vendor_detail_controller.dart';

/// GET /api/vendors/{id} for a salesman (SPEC section 2.3) — the same
/// `{vendor, active_subscription}` shape `GET /vendors/me` returns, which is
/// why VendorMeModel parses both.
class _RecordingVendorDataSource extends DataSource {
  int? requestedVendorId;
  bool succeed = true;
  Map<String, dynamic>? payload;

  @override
  Future<CommonResponse?> vendorShowAPI({required int vendorId}) async {
    requestedVendorId = vendorId;

    if (!succeed) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'NOT_FOUND', 'message': 'Not found.'},
      }, statusCode: 404);
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': payload ?? {'vendor': <String, dynamic>{}, 'active_subscription': null},
      'error': null,
    });
  }
}

void main() {
  Map<String, dynamic> subscribedPayload() => {
        'vendor': {
          'id': 7,
          'business_name': 'Royal Style Salon',
          'owner_name': 'Asha Patel',
          'phone': '9990000001',
          'email': 'asha@example.com',
          'status': 'active',
          'has_active_subscription': true,
        },
        'active_subscription': {
          'plan_name': 'Platinum',
          'end_date': '2027-01-01',
          'days_remaining': 365,
          'quota': {
            'categories': {'used': 1, 'max': 5},
            'subcategories': {'used': 2, 'max': 10},
            'zones': {'used': 3, 'max': 8},
            'photos': {'used': 0, 'max': 20},
            'videos': {'used': 0, 'max': 3},
          },
          'items': {
            'categories': [
              {'id': 1, 'name': 'AC Service'},
            ],
            'subcategories': [
              {'id': 11, 'name': 'AC Installation'},
              {'id': 12, 'name': 'AC Gas Filling'},
            ],
            'zones': [
              {'id': 101, 'name': 'Bodakdev'},
            ],
          },
        },
      };

  testWidgets('it requests the vendor it was given and parses plan, quota and items', (tester) async {
    final fake = _RecordingVendorDataSource()..payload = subscribedPayload();
    DataSource.instance = fake;

    final controller = SalesmanVendorDetailController(vendorId: 7);
    await controller.fetchVendorAPI();

    expect(fake.requestedVendorId, 7);
    expect(controller.hasError, isFalse);

    final vendor = controller.vendor;
    expect(vendor, isNotNull);
    expect(vendor!.businessName, 'Royal Style Salon');
    expect(vendor.status, 'active');

    final subscription = vendor.activeSubscription;
    expect(subscription, isNotNull);
    expect(subscription!.planName, 'Platinum');
    expect(subscription.daysRemaining, 365);
    expect(subscription.zones?.used, 3);
    expect(subscription.zones?.max, 8);

    // Names, not just counts — the whole reason the salesman opens this.
    expect(
      subscription.selectedSubcategories.map((e) => e.name),
      containsAll(['AC Installation', 'AC Gas Filling']),
    );
    expect(subscription.selectedZones.single.name, 'Bodakdev');
  });

  testWidgets('a vendor with no subscription parses without error', (tester) async {
    // The common case in the salesman flow: a draft vendor added but not yet
    // subscribed. A null active_subscription is a state, not a failure.
    DataSource.instance = _RecordingVendorDataSource()
      ..payload = {
        'vendor': {
          'id': 8,
          'business_name': 'Sharma Mobile Hub',
          'status': 'draft',
          'has_active_subscription': false,
        },
        'active_subscription': null,
      };

    final controller = SalesmanVendorDetailController(vendorId: 8);
    await controller.fetchVendorAPI();

    expect(controller.hasError, isFalse);
    expect(controller.vendor?.businessName, 'Sharma Mobile Hub');
    expect(controller.vendor?.activeSubscription, isNull);
    expect(controller.vendor?.hasActiveSubscription, isFalse);
  });

  testWidgets('a rejected request sets the error state and leaves no stale vendor', (tester) async {
    DataSource.instance = _RecordingVendorDataSource()..succeed = false;

    final controller = SalesmanVendorDetailController(vendorId: 9);
    await controller.fetchVendorAPI();

    expect(controller.hasError, isTrue);
    expect(controller.vendor, isNull);
    expect(controller.isLoading, isFalse);
  });
}
