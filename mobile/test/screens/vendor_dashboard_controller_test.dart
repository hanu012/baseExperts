import 'package:easy_localization/easy_localization.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/constants/string_res.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_dashboard_module/vendor_dashboard_controller.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_dashboard_module/vendor_dashboard_view.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_select_plan_module/vendor_select_plan_view.dart';
import 'package:service_marketplace/widgets/base_vendor.dart';

/// Answers GET /api/vendors/me the way task 4.2's real endpoint does:
/// active_subscription is null with no subscription, or plan/quota/days
/// detail with one — never fake leads/rating/photos data (Phase 5).
class _RecordingVendorMeDataSource extends DataSource {
  bool hasActiveSubscription = true;
  bool fails = false;

  /// Held open so a test can inspect the frame WHILE the request is in
  /// flight. With an instantly-resolving fake the loading state is over
  /// before the first pump, which is exactly the frame the refresh flash
  /// happened on.
  Duration delay = Duration.zero;

  @override
  Future<CommonResponse?> vendorMeAPI() async {
    if (delay > Duration.zero) {
      await Future<void>.delayed(delay);
    }

    if (fails) {
      return null;
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'vendor': {
          'id': 1,
          'business_name': 'Cool Air Services',
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
                'days_remaining': 45,
                'quota': {
                  'categories': {'used': 1, 'max': 3},
                  'subcategories': {'used': 2, 'max': 6},
                  'zones': {'used': 1, 'max': 2},
                },
                'items': {
                  'categories': [
                    {'id': 1, 'name': 'AC Repair'},
                  ],
                  'subcategories': [
                    {'id': 10, 'name': 'Gas Filling'},
                    {'id': 11, 'name': 'Installation'},
                  ],
                  'zones': [
                    {'id': 100, 'name': 'Gota'},
                  ],
                },
              }
            : null,
      },
      'error': null,
    });
  }
}

void main() {
  testWidgets('a fetch populates vendorMe with plan and quota detail', (tester) async {
    final fake = _RecordingVendorMeDataSource()..hasActiveSubscription = true;
    DataSource.instance = fake;

    await tester.pumpWidget(GetMaterialApp(home: const VendorDashboardView()));
    await tester.pumpAndSettle();

    expect(find.text('Cool Air Services'), findsOneWidget);
    expect(find.text('Gold'), findsOneWidget);
    expect(find.text('45'), findsOneWidget);
    // Overview renders quota as a used/max pair per row rather than the
    // old "1 of 3" sentence. findRichText because the pair is one
    // RichText of two differently-styled spans, not two Text widgets.
    expect(find.text('1 / 3', findRichText: true), findsOneWidget);
    expect(find.text('2 / 6', findRichText: true), findsOneWidget);
    expect(find.text('1 / 2', findRichText: true), findsOneWidget);
  });

  testWidgets('the Services tab shows the selected item names and remaining quota', (
    tester,
  ) async {
    final fake = _RecordingVendorMeDataSource()..hasActiveSubscription = true;
    DataSource.instance = fake;

    await tester.pumpWidget(GetMaterialApp(home: const VendorDashboardView()));
    await tester.pumpAndSettle();

    await tester.tap(find.text(tr(StringRes.servicesTab)));
    await tester.pumpAndSettle();

    expect(find.text('AC Repair'), findsOneWidget);
    expect(find.text('Gas Filling'), findsOneWidget);
    expect(find.text('Installation'), findsOneWidget);
    expect(find.text('Gota'), findsOneWidget);
    // One "N of M used" line and one remaining-count badge per section —
    // categories, subcategories and zones. Asserted by count rather than
    // by value because this tree is not wrapped in EasyLocalization, so
    // a parameterised key renders as the bare key with no args
    // substituted; the counts themselves are covered by the Overview
    // test above, which reads real numbers out of RichText.
    expect(find.text(tr(StringRes.vendorUsedOf)), findsNWidgets(3));
    expect(find.text(tr(StringRes.vendorLeftCount)), findsNWidgets(3));
    expect(find.byType(VendorPrimaryButton), findsOneWidget);
  });

  testWidgets('no active subscription shows the fallback state with a way to subscribe', (
    tester,
  ) async {
    final fake = _RecordingVendorMeDataSource()..hasActiveSubscription = false;
    DataSource.instance = fake;

    await tester.pumpWidget(GetMaterialApp(home: const VendorDashboardView()));
    await tester.pumpAndSettle();

    expect(find.text('Cool Air Services'), findsNothing);
    expect(find.byType(VendorPrimaryButton), findsOneWidget);

    await tester.tap(find.byType(VendorPrimaryButton));
    await tester.pumpAndSettle();

    expect(find.byType(VendorSelectPlanView), findsOneWidget);
  });

  testWidgets('a failed fetch does not crash and simply shows the fallback state', (
    tester,
  ) async {
    final fake = _RecordingVendorMeDataSource()..fails = true;
    DataSource.instance = fake;

    await tester.pumpWidget(GetMaterialApp(home: const VendorDashboardView()));
    await tester.pumpAndSettle();

    expect(find.byType(VendorDashboardView), findsOneWidget);
    expect(find.text('Cool Air Services'), findsNothing);
  });

  /// Refreshing must not make the screen claim the subscription vanished.
  ///
  /// `hasSubscription` used to be `!isLoading && subscription != null`.
  /// A pull-to-refresh keeps the previous vendorMe and only flips
  /// isLoading, so for the length of the round trip the dashboard and its
  /// bottom bar were replaced by "You don't have an active subscription"
  /// and then replaced back — a visible flash on every refresh.
  testWidgets('refreshing an active dashboard never flashes the empty state', (
    tester,
  ) async {
    final fake = _RecordingVendorMeDataSource();
    DataSource.instance = fake;

    await tester.pumpWidget(GetMaterialApp(home: const VendorDashboardView()));
    await tester.pumpAndSettle();

    expect(find.text('Gold'), findsOneWidget);

    final controller = Get.find<VendorDashboardController>();

    // Hold the next response open so the in-flight frame can be read.
    fake.delay = const Duration(milliseconds: 300);

    final pending = controller.fetchVendorMeAPI();
    await tester.pump();

    // Mid-refresh: still loading, old data still held — the exact frame
    // the empty state used to take over.
    expect(controller.isLoading, isTrue);
    expect(find.text(tr(StringRes.noActiveSubscription)), findsNothing);
    expect(find.byType(VendorBottomNav), findsOneWidget);
    expect(find.text('Gold'), findsOneWidget);

    // pump(duration), not a bare await: the delayed response only fires
    // when the test clock advances, so awaiting it directly would hang.
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    await pending;

    expect(controller.isLoading, isFalse);
    expect(find.text('Gold'), findsOneWidget);
  });
}
