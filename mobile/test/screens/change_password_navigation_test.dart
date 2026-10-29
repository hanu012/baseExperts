import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/user_model.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/account_verification_module/account_verification_view.dart';
import 'package:service_marketplace/screens/auth_flow/change_password_module/change_password_controller.dart';
import 'package:service_marketplace/screens/auth_flow/change_password_module/change_password_view.dart';
import 'package:service_marketplace/screens/auth_flow/my_vendors_module/my_vendors_controller.dart';
import 'package:service_marketplace/screens/auth_flow/salesman_home_module/salesman_home_view.dart';
import 'package:service_marketplace/utils/injector.dart';

/// How this screen is LEFT, which differs by how it was entered.
///
/// The voluntary case is a regression test for a real crash: finishing a
/// password change opened from the profile screen used to Get.offAll to a
/// fresh SalesmanHomeView while the original was still mounted. The new
/// MyVendorsView's GetBuilder reused the already-registered
/// MyVendorsController instead of creating its own, then the outgoing
/// route's dispose ran Get.delete on that same controller — disposing the
/// search field's TextEditingController while the live screen was
/// rendering it ("A TextEditingController was used after being disposed").
class _ChangePasswordDataSource extends DataSource {
  _ChangePasswordDataSource({this.approvalStatus = 'approved'});

  final String approvalStatus;
  Map<String, dynamic>? capturedBody;

  @override
  Future<CommonResponse?> changePasswordAPI({
    required Map<String, dynamic> body,
  }) async {
    capturedBody = body;

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'user': {
          'id': 1,
          'name': 'Sahil Patel',
          'email': 'sahil@example.com',
          'role': 'salesman',
          // Cleared by the change — which is exactly why the controller
          // has to capture isForced before calling this.
          'must_change_password': false,
          'approval_status': approvalStatus,
        },
      },
      'error': null,
    });
  }

  /// Stubbed so building SalesmanHomeView in the voluntary case does not
  /// reach real Dio and leave a pending timer.
  @override
  Future<CommonResponse?> salesmanMeAPI() async {
    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'salesman': {'id': 1, 'name': 'Sahil Patel', 'employee_code': 'SM-1'},
        'stats': {
          'total_vendors': 0,
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
    return CommonResponse.fromJson({'success': true, 'data': [], 'error': null});
  }

  @override
  Future<CommonResponse?> salesmanCommissionsAPI() async {
    return CommonResponse.fromJson({
      'success': true,
      'data': {'summary': {}, 'rows': []},
      'error': null,
    });
  }
}

ChangePasswordController _filled() {
  final controller = Get.find<ChangePasswordController>();
  controller.currentPasswordController.text = 'temp-password-123';
  controller.newPasswordController.text = 'correct-horse-battery';
  controller.confirmPasswordController.text = 'correct-horse-battery';
  return controller;
}

void main() {
  setUpAll(() {
    FlavorConfig.initialize(FlavorConfig.salesman);
  });

  tearDown(() {
    Get.reset();
    Injector.userData = null;
  });

  testWidgets(
    'a voluntary change pops back and leaves the home controllers alive',
    (tester) async {
      DataSource.instance = _ChangePasswordDataSource();

      // Voluntary: the flag is already false when the screen opens.
      Injector.userData = UserModel(
        id: 1,
        name: 'Sahil Patel',
        email: 'sahil@example.com',
        role: 'salesman',
        approvalStatus: 'approved',
      );

      await tester.pumpWidget(const GetMaterialApp(home: SalesmanHomeView()));
      await tester.pumpAndSettle();

      final searchController =
          Get.find<MyVendorsController>().searchController;

      Get.to(() => const ChangePasswordView());
      await tester.pumpAndSettle();

      await _filled().changePasswordAPI();
      await tester.pumpAndSettle();

      expect(find.byType(ChangePasswordView), findsNothing);
      expect(find.byType(SalesmanHomeView), findsOneWidget);

      // The crash: the home underneath was never torn down, so its search
      // controller must still be usable. Touching a disposed
      // TextEditingController throws.
      expect(Get.isRegistered<MyVendorsController>(), isTrue);
      expect(
        identical(Get.find<MyVendorsController>().searchController, searchController),
        isTrue,
      );
      expect(() => searchController.text = 'cool air', returnsNormally);
    },
  );

  testWidgets('a forced change replaces the stack with home', (tester) async {
    DataSource.instance = _ChangePasswordDataSource();

    Injector.userData = UserModel(
      id: 1,
      name: 'Sahil Patel',
      email: 'sahil@example.com',
      role: 'salesman',
      approvalStatus: 'approved',
    )..mustChangePassword = true;

    await tester.pumpWidget(const GetMaterialApp(home: ChangePasswordView()));
    await tester.pumpAndSettle();

    expect(Get.find<ChangePasswordController>().isForced, isTrue);

    await _filled().changePasswordAPI();
    await tester.pumpAndSettle();

    expect(find.byType(ChangePasswordView), findsNothing);
    expect(find.byType(SalesmanHomeView), findsOneWidget);
  });

  /// The screen is shared by all three apps. A vendor finishing a forced
  /// change used to be dropped onto the salesman home.
  testWidgets('a forced change lands on the flavour\'s own home', (tester) async {
    FlavorConfig.initialize(FlavorConfig.vendor);
    addTearDown(() => FlavorConfig.initialize(FlavorConfig.salesman));

    DataSource.instance = _ChangePasswordDataSource();

    Injector.userData = UserModel(
      id: 1,
      name: 'Asha Patel',
      email: 'asha@example.com',
      role: 'vendor',
      approvalStatus: 'approved',
    )..mustChangePassword = true;

    await tester.pumpWidget(const GetMaterialApp(home: ChangePasswordView()));
    await tester.pumpAndSettle();

    await _filled().changePasswordAPI();
    await tester.pumpAndSettle();

    expect(find.byType(SalesmanHomeView), findsNothing);
  });

  /// Same gate order as the server and app.dart: password change first,
  /// then approval. An admin can reset the password of a pending account,
  /// and home would fail every call with ACCOUNT_PENDING_APPROVAL.
  testWidgets('a forced change by an unapproved account goes to verification, not home', (tester) async {
    DataSource.instance = _ChangePasswordDataSource(approvalStatus: 'pending');

    Injector.userData = UserModel(
      id: 1,
      name: 'Sahil Patel',
      email: 'sahil@example.com',
      role: 'salesman',
      approvalStatus: 'pending',
    )..mustChangePassword = true;

    await tester.pumpWidget(const GetMaterialApp(home: ChangePasswordView()));
    await tester.pumpAndSettle();

    await _filled().changePasswordAPI();
    await tester.pumpAndSettle();

    expect(find.byType(SalesmanHomeView), findsNothing);
    expect(find.byType(AccountVerificationView), findsOneWidget);
  });
}
