import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_select_plan_module/vendor_select_plan_controller.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_select_services_module/vendor_select_services_view.dart';

/// vendor_select_plan_module resolves its own vendor_id from GET
/// /api/vendors/me rather than taking one as a constructor argument (task
/// 4.2) — this fake answers both plansAPI and vendorMeAPI so
/// fetchPlansAPI's Future.wait([...]) resolves.
class _RecordingPlansDataSource extends DataSource {
  bool vendorMeFails = false;

  @override
  Future<CommonResponse?> plansAPI() async {
    return CommonResponse.fromJson({
      'success': true,
      'data': [
        {
          'id': 1,
          'name': 'Silver',
          'price_paise': 99900,
          'duration_days': 30,
          'max_categories': 1,
          'max_subcategories': 3,
          'max_zones': 1,
        },
        {
          'id': 2,
          'name': 'Gold',
          'price_paise': 249900,
          'duration_days': 90,
          'max_categories': 3,
          'max_subcategories': 6,
          'max_zones': 2,
        },
      ],
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> vendorMeAPI() async {
    if (vendorMeFails) {
      return null;
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'vendor': {
          'id': 42,
          'business_name': 'Cool Air',
          'owner_name': 'Asha Patel',
          'phone': '9812345678',
          'email': 'vendor@example.com',
          'status': 'pending_payment',
          'has_active_subscription': false,
        },
        'active_subscription': null,
      },
      'error': null,
    });
  }
}

void main() {
  testWidgets('fetchPlansAPI loads plans and resolves vendorId from /vendors/me', (
    tester,
  ) async {
    DataSource.instance = _RecordingPlansDataSource();

    final controller = VendorSelectPlanController();

    await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
    await controller.fetchPlansAPI();
    await tester.pumpAndSettle();

    expect(controller.plans.length, 2);
    expect(controller.vendorId, 42);
    // Defaults to the first plan rather than leaving nothing selected.
    expect(controller.selectedPlanId, 1);

    controller.onClose();
  });

  testWidgets('continueToServices navigates with the selected plan and resolved vendorId', (
    tester,
  ) async {
    DataSource.instance = _RecordingPlansDataSource();

    final controller = VendorSelectPlanController();

    await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
    await controller.fetchPlansAPI();
    controller.selectPlan(2);

    controller.continueToServices();
    await tester.pumpAndSettle();

    final view = tester.widget<VendorSelectServicesView>(find.byType(VendorSelectServicesView));
    expect(view.vendorId, 42);
    expect(view.plan.id, 2);
    expect(view.plan.name, 'Gold');

    controller.onClose();
  });

  testWidgets('continueToServices does not navigate when vendorId failed to resolve', (
    tester,
  ) async {
    DataSource.instance = _RecordingPlansDataSource()..vendorMeFails = true;

    final controller = VendorSelectPlanController();

    await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));
    await controller.fetchPlansAPI();
    expect(controller.vendorId, isNull);

    controller.continueToServices();
    await tester.pumpAndSettle();

    expect(find.byType(VendorSelectServicesView), findsNothing);

    controller.onClose();
  });
}
