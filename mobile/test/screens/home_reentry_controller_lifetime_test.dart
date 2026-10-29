import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/subscription_model.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/my_vendors_module/my_vendors_controller.dart';
import 'package:service_marketplace/screens/auth_flow/salesman_home_module/salesman_home_view.dart';
import 'package:service_marketplace/screens/vendor_flow/subscription_confirmation_module/subscription_confirmation_controller.dart';

/// Finishing Add Vendor must not kill the home screen it started from.
///
/// This is the "A TextEditingController was used after being disposed"
/// crash. SubscriptionConfirmationController.done() used to Get.offAll to a
/// fresh SalesmanHomeView while the original was still mounted at the root
/// of the stack. The new MyVendorsView's GetBuilder reuses an
/// already-registered controller rather than creating its own
/// (get_state.dart initState), and tearing the old route down then deletes
/// that shared instance — disposing the search field's
/// TextEditingController under a screen that is rendering it.
///
/// Note the fix had to be the navigation, not the GetBuilder teardown:
/// removing the explicit `dispose: (_) => Get.delete<T>()` does NOT help,
/// because the outgoing route is the CREATOR and GetBuilder's own
/// autoRemove deletes the controller on its way out regardless.
class _HomeDataSource extends DataSource {
  @override
  Future<CommonResponse?> salesmanMeAPI() async {
    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'salesman': {'id': 1, 'name': 'Sahil Patel', 'employee_code': 'SM-1'},
        'stats': {
          'total_vendors': 1,
          'subscribed_vendors': 0,
          'earnings_paise': 0,
          'pending_earnings_paise': 0,
          'target': {
            'monthly_target_paise': 0,
            'achieved_paise': 0,
            'percent': null,
          },
        },
      },
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> salesmanVendorsAPI() async {
    return CommonResponse.fromJson({
      'success': true,
      'data': [
        {
          'id': 1,
          'business_name': 'Cool Air',
          'status': 'active',
          'plan_name': 'Gold',
          'days_to_expiry': 10,
        },
      ],
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> salesmanCommissionsAPI() async {
    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'pending_amount_paise': 0,
        'paid_amount_paise': 0,
        'pending_count': 0,
        'paid_count': 0,
      },
      'error': null,
    });
  }
}

void main() {
  tearDown(Get.reset);

  testWidgets(
    'finishing Add Vendor leaves the home search field usable',
    (tester) async {
      DataSource.instance = _HomeDataSource();

      await tester.pumpWidget(const GetMaterialApp(home: SalesmanHomeView()));
      await tester.pumpAndSettle();

      final searchController = Get.find<MyVendorsController>().searchController;

      // Stand in for the pushed Add Vendor flow (details → plan → services
      // → confirmation). Only the depth matters here, not the screens.
      Get.to(() => const SizedBox.shrink());
      await tester.pumpAndSettle();
      Get.to(() => const SizedBox.shrink());
      await tester.pumpAndSettle();

      SubscriptionConfirmationController(
        businessName: 'Cool Air',
        loginEmail: 'asha@example.com',
        temporaryPassword: 'temp-1234',
        subscription: SubscriptionModel(),
      ).done();
      await tester.pumpAndSettle();

      // Back on the ORIGINAL home, not a rebuilt one.
      expect(find.byType(SalesmanHomeView), findsOneWidget);

      // The crash surfaced on the next frame, when the search field read a
      // controller the outgoing route had just disposed.
      await tester.pump();
      expect(tester.takeException(), isNull);

      expect(Get.isRegistered<MyVendorsController>(), isTrue);
      expect(
        identical(Get.find<MyVendorsController>().searchController, searchController),
        isTrue,
      );
      expect(() => searchController.text = 'cool air', returnsNormally);
    },
  );
}
