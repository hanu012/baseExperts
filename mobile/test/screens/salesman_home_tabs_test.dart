import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/constants/constant.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/earnings_module/earnings_controller.dart';
import 'package:service_marketplace/screens/auth_flow/earnings_module/earnings_view.dart';
import 'package:service_marketplace/screens/auth_flow/my_vendors_module/my_vendors_controller.dart';
import 'package:service_marketplace/screens/auth_flow/my_vendors_module/my_vendors_view.dart';
import 'package:service_marketplace/screens/auth_flow/salesman_home_module/salesman_home_view.dart';
import 'package:service_marketplace/screens/auth_flow/salesman_vendor_detail_module/salesman_vendor_detail_view.dart';
import 'package:service_marketplace/screens/vendor_flow/select_plan_module/select_plan_view.dart';
import 'package:service_marketplace/screens/vendor_flow/add_vendor_module/add_vendor_view.dart';

/// GET /api/salesmen/me/vendors and GET /api/salesmen/me/commissions
/// (SPEC sections 2.3, 2.4).
class _RecordingSalesmanDataSource extends DataSource {
  List<Map<String, dynamic>> vendorRows = [];
  Map<String, dynamic>? commissionSummary;
  bool vendorsSucceed = true;
  bool commissionsSucceed = true;

  /// The redesigned home hero fetches this on init for its stat tiles;
  /// without a stub the controller would hit real Dio and leave a pending
  /// timer in every test that builds the screen.
  @override
  Future<CommonResponse?> salesmanMeAPI() async {
    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'salesman': {'id': 1, 'name': 'Ramprakash', 'employee_code': 'SM-1'},
        'stats': {
          'total_vendors': vendorRows.length,
          'subscribed_vendors': 0,
          'earnings_paise': 0,
          'pending_earnings_paise': 0,
          'target': {'monthly_target_paise': 0, 'achieved_paise': 0, 'percent': null},
        },
      },
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> salesmanVendorsAPI() async {
    if (!vendorsSucceed) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'FORBIDDEN', 'message': 'This action is unauthorized.'},
      }, statusCode: 403);
    }

    return CommonResponse.fromJson({'success': true, 'data': vendorRows, 'error': null});
  }

  @override
  Future<CommonResponse?> salesmanCommissionsAPI() async {
    if (!commissionsSucceed) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'FORBIDDEN', 'message': 'This action is unauthorized.'},
      }, statusCode: 403);
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': commissionSummary ??
          {
            'pending_amount_paise': 0,
            'paid_amount_paise': 0,
            'pending_count': 0,
            'paid_count': 0,
          },
      'error': null,
    });
  }

  int? capturedShowVendorId;

  /// Resuming a draft re-reads the vendor, because the list payload has no
  /// login email and the plan screen has to pass one on to Subscribe.
  @override
  Future<CommonResponse?> vendorShowAPI({required int vendorId}) async {
    capturedShowVendorId = vendorId;

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'vendor': {
          'id': vendorId,
          'business_name': 'Still Draft',
          'owner_name': 'Asha Patel',
          'email': 'asha@example.com',
          'phone': '9812345678',
          'status': 'draft',
        },
        'active_subscription': null,
      },
      'error': null,
    });
  }

  /// SelectPlanView fetches these on init; stubbed empty so the resumed
  /// screen settles instead of reaching real Dio.
  @override
  Future<CommonResponse?> plansAPI() async {
    return CommonResponse.fromJson({'success': true, 'data': [], 'error': null});
  }
}

void main() {
  group('MyVendorsController', () {
    testWidgets('parses vendor rows including null plan/days for a draft vendor', (
      tester,
    ) async {
      final fake = _RecordingSalesmanDataSource()
        ..vendorRows = [
          {
            'id': 1,
            'business_name': 'Cool Air',
            'status': 'active',
            'plan_name': 'Gold',
            'days_to_expiry': 10,
          },
          {
            'id': 2,
            'business_name': 'Still Draft',
            'status': 'draft',
            'plan_name': null,
            'days_to_expiry': null,
          },
        ];
      DataSource.instance = fake;

      final controller = MyVendorsController();
      await controller.fetchVendorsAPI();

      expect(controller.vendors.length, 2);
      expect(controller.vendors[0].planName, 'Gold');
      expect(controller.vendors[0].daysToExpiry, 10);
      expect(controller.vendors[1].planName, isNull);
      expect(controller.vendors[1].daysToExpiry, isNull);

      controller.onClose();
    });

    testWidgets('an expired subscription keeps its negative days-to-expiry, not clamped', (
      tester,
    ) async {
      final fake = _RecordingSalesmanDataSource()
        ..vendorRows = [
          {
            'id': 3,
            'business_name': 'Expired Vendor',
            'status': 'expired',
            'plan_name': 'Silver',
            'days_to_expiry': -4,
          },
        ];
      DataSource.instance = fake;

      final controller = MyVendorsController();
      await controller.fetchVendorsAPI();

      expect(controller.vendors.single.daysToExpiry, -4);

      controller.onClose();
    });

    testWidgets('a failed fetch leaves the vendor list empty rather than crashing', (
      tester,
    ) async {
      final fake = _RecordingSalesmanDataSource()..vendorsSucceed = false;
      DataSource.instance = fake;

      final controller = MyVendorsController();
      await controller.fetchVendorsAPI();

      expect(controller.vendors, isEmpty);
      expect(controller.isLoading, isFalse);

      controller.onClose();
    });

    testWidgets(
      'the rendered row shows "Expired N days ago", not a raw negative number, without overflowing',
      (tester) async {
        final fake = _RecordingSalesmanDataSource()
          ..vendorRows = [
            {
              'id': 3,
              'business_name': 'Expired Vendor',
              'status': 'expired',
              'plan_name': 'Silver',
              'days_to_expiry': -4,
            },
          ];
        DataSource.instance = fake;

        await tester.pumpWidget(const GetMaterialApp(home: MyVendorsView()));
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
        expect(find.textContaining('-4'), findsNothing);
        expect(find.textContaining('4'), findsOneWidget);
      },
    );

    /// A draft is an unfinished sale. Tapping it used to open the
    /// read-only detail screen, which left the salesman with no way to
    /// carry on — so it now re-enters the onboarding at plan selection,
    /// the first step the draft has not completed.
    testWidgets('tapping a draft resumes the onboarding at plan selection', (
      tester,
    ) async {
      final fake = _RecordingSalesmanDataSource()
        ..vendorRows = [
          {
            'id': 7,
            'business_name': 'Still Draft',
            'status': 'draft',
            'plan_name': null,
            'days_to_expiry': null,
          },
        ];
      DataSource.instance = fake;

      await tester.pumpWidget(const GetMaterialApp(home: MyVendorsView()));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Still Draft'));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.byType(SelectPlanView), findsOneWidget);
      expect(find.byType(SalesmanVendorDetailView), findsNothing);
      expect(fake.capturedShowVendorId, 7);
    });

    /// The other half of the branch. An expired vendor also shows "Not
    /// subscribed", so routing on that would have sent finished vendors
    /// into the onboarding too — the branch is on status, not on quota.
    testWidgets('tapping a non-draft vendor still opens the detail screen', (
      tester,
    ) async {
      DataSource.instance = _RecordingSalesmanDataSource()
        ..vendorRows = [
          {
            'id': 9,
            'business_name': 'Lapsed Vendor',
            'status': 'expired',
            'plan_name': 'Gold',
            'days_to_expiry': -4,
          },
        ];

      await tester.pumpWidget(const GetMaterialApp(home: MyVendorsView()));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Lapsed Vendor'));
      await tester.pumpAndSettle();

      expect(find.byType(SalesmanVendorDetailView), findsOneWidget);
      expect(find.byType(SelectPlanView), findsNothing);
    });

    testWidgets(
      'the empty-state Add vendor button navigates to AddVendorView',
      (tester) async {
        DataSource.instance = _RecordingSalesmanDataSource();

        await tester.pumpWidget(const GetMaterialApp(home: MyVendorsView()));
        await tester.pumpAndSettle();

        // .tr() falls back to the raw key untranslated here since this
        // test doesn't wrap the tree in EasyLocalization, matching the
        // pattern the rest of this file's widget tests already rely on.
        await tester.tap(find.text('addVendorTitle'));
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
        expect(find.byType(AddVendorView), findsOneWidget);
      },
    );
  });

  group('SalesmanHomeView', () {
    testWidgets(
      'the hero Add vendor button navigates to AddVendorView',
      (tester) async {
        DataSource.instance = _RecordingSalesmanDataSource();

        await tester.pumpWidget(const GetMaterialApp(home: SalesmanHomeView()));
        await tester.pumpAndSettle();

        await tester.tap(find.byIcon(Icons.add).first);
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
        expect(find.byType(AddVendorView), findsOneWidget);
      },
    );

    /// Earnings is currently hidden across the salesman app
    /// (Constants.showSalesmanEarnings). Written to follow the flag rather
    /// than hardcode "absent", so flipping it back on does not leave a
    /// test asserting the opposite of what the app now does.
    testWidgets('the Earnings tab and stat tile follow the earnings flag', (
      tester,
    ) async {
      DataSource.instance = _RecordingSalesmanDataSource();

      await tester.pumpWidget(const GetMaterialApp(home: SalesmanHomeView()));
      await tester.pumpAndSettle();

      final matcher = Constants.showSalesmanEarnings ? findsOneWidget : findsNothing;

      expect(find.text('earningsTab'), matcher);
      expect(find.text('earningsLabel'), matcher);

      // My Vendors is never hidden, so a bare "findsNothing" above passing
      // because the screen failed to build would still be caught here.
      expect(find.text('myVendorsTab'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('EarningsController', () {
    testWidgets('parses pending/paid totals separately', (tester) async {
      final fake = _RecordingSalesmanDataSource()
        ..commissionSummary = {
          'pending_amount_paise': 3000,
          'paid_amount_paise': 5000,
          'pending_count': 2,
          'paid_count': 1,
        };
      DataSource.instance = fake;

      final controller = EarningsController();
      await controller.fetchCommissionsAPI();

      expect(controller.summary?.pendingAmountPaise, 3000);
      expect(controller.summary?.paidAmountPaise, 5000);
      expect(controller.summary?.pendingCount, 2);
      expect(controller.summary?.paidCount, 1);

      controller.onClose();
    });

    testWidgets('a failed fetch leaves summary null rather than crashing', (tester) async {
      final fake = _RecordingSalesmanDataSource()..commissionsSucceed = false;
      DataSource.instance = fake;

      final controller = EarningsController();
      await controller.fetchCommissionsAPI();

      expect(controller.summary, isNull);
      expect(controller.isLoading, isFalse);

      controller.onClose();
    });

    testWidgets('the rendered cards show rupees, not paise, without overflowing', (tester) async {
      final fake = _RecordingSalesmanDataSource()
        ..commissionSummary = {
          'pending_amount_paise': 300000,
          'paid_amount_paise': 500000,
          'pending_count': 2,
          'paid_count': 1,
        };
      DataSource.instance = fake;

      await tester.pumpWidget(const GetMaterialApp(home: EarningsView()));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('₹3,000.00'), findsOneWidget);
      expect(find.text('₹5,000.00'), findsOneWidget);
    });
  });
}
