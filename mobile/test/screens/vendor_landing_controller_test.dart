import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_dashboard_module/vendor_dashboard_view.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_landing_module/vendor_landing_view.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_select_plan_module/vendor_select_plan_view.dart';
import 'package:service_marketplace/widgets/base_button.dart';

/// Covers the SPEC section 3.2 branch VendorLandingController exists for:
/// has_active_subscription decides dashboard vs plan-selection. The screens
/// it lands on each fire their own onInit fetch, so this fake answers
/// vendorMeAPI/plansAPI consistently rather than only for the first call —
/// otherwise pumpAndSettle would cascade into a real, unstubbed network
/// call on whichever screen the landing gate navigates to.
class _RecordingVendorMeDataSource extends DataSource {
  bool hasActiveSubscription = false;
  bool fails = false;
  int callCount = 0;

  @override
  Future<CommonResponse?> vendorMeAPI() async {
    callCount++;

    if (fails) {
      return null;
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'vendor': {
          'id': 1,
          'business_name': 'Cool Air',
          'owner_name': 'Asha Patel',
          'phone': '9812345678',
          'email': 'vendor@example.com',
          'status': hasActiveSubscription ? 'active' : 'pending_payment',
          'has_active_subscription': hasActiveSubscription,
        },
        'active_subscription': hasActiveSubscription
            ? {
                'plan_name': 'Gold',
                'end_date': '2027-01-01',
                'days_remaining': 120,
                'quota': {
                  'categories': {'used': 1, 'max': 3},
                  'subcategories': {'used': 2, 'max': 6},
                  'zones': {'used': 1, 'max': 2},
                },
              }
            : null,
      },
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> plansAPI() async {
    return CommonResponse.fromJson({'success': true, 'data': <dynamic>[], 'error': null});
  }
}

void main() {
  testWidgets('an active subscription lands on the dashboard', (tester) async {
    final fake = _RecordingVendorMeDataSource()..hasActiveSubscription = true;
    DataSource.instance = fake;

    await tester.pumpWidget(GetMaterialApp(home: const VendorLandingView()));
    await tester.pumpAndSettle();

    expect(find.byType(VendorDashboardView), findsOneWidget);
    expect(find.byType(VendorLandingView), findsNothing);
  });

  testWidgets('no active subscription lands on plan selection', (tester) async {
    final fake = _RecordingVendorMeDataSource()..hasActiveSubscription = false;
    DataSource.instance = fake;

    await tester.pumpWidget(GetMaterialApp(home: const VendorLandingView()));
    await tester.pumpAndSettle();

    expect(find.byType(VendorSelectPlanView), findsOneWidget);
    expect(find.byType(VendorLandingView), findsNothing);
  });

  testWidgets('a failed check shows the error/retry state and does not navigate', (tester) async {
    final fake = _RecordingVendorMeDataSource()..fails = true;
    DataSource.instance = fake;

    await tester.pumpWidget(GetMaterialApp(home: const VendorLandingView()));
    await tester.pumpAndSettle();

    expect(find.byType(VendorLandingView), findsOneWidget);
    expect(find.byType(VendorDashboardView), findsNothing);
    expect(find.byType(VendorSelectPlanView), findsNothing);
    expect(fake.callCount, 1);
  });

  testWidgets('tapping retry re-runs the check', (tester) async {
    final fake = _RecordingVendorMeDataSource()..fails = true;
    DataSource.instance = fake;

    await tester.pumpWidget(GetMaterialApp(home: const VendorLandingView()));
    await tester.pumpAndSettle();
    expect(fake.callCount, 1);

    fake.fails = false;
    fake.hasActiveSubscription = false;

    await tester.tap(find.byType(BaseRaisedButton));
    await tester.pumpAndSettle();

    // >= 2: retry re-ran the check itself; VendorSelectPlanView's own
    // fetchPlansAPI() also calls vendorMeAPI() to resolve its vendorId, so
    // the exact count depends on that screen's fetch too, not just retry.
    expect(fake.callCount, greaterThanOrEqualTo(2));
    expect(find.byType(VendorSelectPlanView), findsOneWidget);
  });
}
