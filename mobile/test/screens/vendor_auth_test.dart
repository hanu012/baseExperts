import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/account_verification_module/account_verification_view.dart';
import 'package:service_marketplace/screens/auth_flow/change_password_module/change_password_view.dart';
import 'package:service_marketplace/screens/auth_flow/check_email_module/check_email_view.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_landing_module/vendor_landing_view.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_login_module/vendor_login_controller.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_register_module/vendor_register_controller.dart';

/// Captures what would go out over the wire to /api/auth/login and
/// /api/auth/register, instead of making real HTTP calls.
class _RecordingAuthDataSource extends DataSource {
  Map<String, dynamic>? capturedLoginBody;
  Map<String, dynamic>? capturedRegisterBody;

  String loginOutcome = 'success'; // success | invalid | pending

  Map<String, List<String>>? registerFieldErrors;

  /// The server could not send the "set your password" email (it then
  /// keeps nothing).
  bool registerEmailFails = false;

  @override
  Future<CommonResponse?> loginAPI({required Map<String, dynamic> body}) async {
    capturedLoginBody = body;

    if (loginOutcome == 'invalid') {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'INVALID_CREDENTIALS', 'message': 'Bad credentials.'},
      }, statusCode: 401);
    }

    // An unapproved account signs in successfully and is handed a real
    // token — the gate is on the other endpoints, not on login. Getting
    // this wrong in the fake would hide the whole behaviour under test.
    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'user': {
          'id': 1,
          'name': 'Asha Patel',
          'email': body['email'],
          'role': 'vendor',
          'must_change_password': false,
          'approval_status': loginOutcome == 'pending' ? 'pending' : 'approved',
        },
        'token': '1|abc',
      },
      'error': null,
    }, statusCode: 200);
  }

  // VendorLandingController fetches this immediately after login/register
  // navigates. Stubbed to fail fast so these auth tests stay fully offline
  // and don't cascade into whatever vendor_select_plan_module's onInit
  // would also fetch — that branch is covered separately.
  @override
  Future<CommonResponse?> vendorMeAPI() async => null;

  @override
  Future<CommonResponse?> registerAPI({required Map<String, dynamic> body}) async {
    capturedRegisterBody = body;

    if (registerFieldErrors != null) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {
          'code': 'VALIDATION_FAILED',
          'message': 'The given data was invalid.',
          'fields': registerFieldErrors,
        },
      }, statusCode: 422);
    }

    if (registerEmailFails) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {
          'code': 'PASSWORD_LINK_NOT_SENT',
          'message': 'We could not send the email to set your password. Please try again.',
        },
      }, statusCode: 503);
    }

    // No token: the password is set from the emailed link, and the first
    // sign-in comes after that.
    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'user': {
          'id': 2,
          'name': body['name'],
          'email': body['email'],
          'role': 'vendor',
          'must_change_password': true,
          'approval_status': 'pending',
        },
        'password_link_sent_to': 'v***@example.com',
      },
      'error': null,
    }, statusCode: 201);
  }
}

VendorLoginController _fillLoginController(WidgetTester tester) {
  final controller = VendorLoginController();
  controller.emailController.text = 'vendor@example.com';
  controller.passwordController.text = 'correct-horse-battery';
  return controller;
}

Future<void> _pumpLoginForm(WidgetTester tester, VendorLoginController controller) async {
  await tester.pumpWidget(
    GetMaterialApp(
      home: Scaffold(
        body: Form(
          key: controller.formKey,
          child: Column(
            children: [
              TextFormField(
                controller: controller.emailController,
                validator: controller.validateEmail,
              ),
              TextFormField(
                controller: controller.passwordController,
                validator: controller.validatePassword,
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

void main() {
  setUpAll(() {
    FlavorConfig.initialize(FlavorConfig.vendor);
  });

  group('VendorLoginController', () {
    testWidgets('a successful login stores the user and lands on VendorLandingView', (
      tester,
    ) async {
      final fake = _RecordingAuthDataSource();
      DataSource.instance = fake;

      final controller = _fillLoginController(tester);
      await _pumpLoginForm(tester, controller);

      await controller.loginAPI();
      await tester.pumpAndSettle();

      expect(fake.capturedLoginBody?['email'], 'vendor@example.com');
      expect(find.byType(VendorLandingView), findsOneWidget);

      controller.onClose();
    });

    testWidgets('must_change_password routes to ChangePasswordView instead', (tester) async {
      final fake = _RecordingAuthDataSource();
      DataSource.instance = fake;

      // Override to report must_change_password: true for this one test.
      fake.capturedLoginBody = null;

      final controller = _fillLoginController(tester);
      await _pumpLoginForm(tester, controller);

      // Patch the fake's response just for this case by re-assigning.
      DataSource.instance = _MustChangePasswordDataSource();

      await controller.loginAPI();
      await tester.pumpAndSettle();

      expect(find.byType(ChangePasswordView), findsOneWidget);

      controller.onClose();
    });

    /// The sign-in itself succeeds — what changes is where it lands.
    testWidgets('an unapproved account lands on the verification screen', (
      tester,
    ) async {
      final fake = _RecordingAuthDataSource()..loginOutcome = 'pending';
      DataSource.instance = fake;

      final controller = _fillLoginController(tester);
      await _pumpLoginForm(tester, controller);

      await controller.loginAPI();
      await tester.pumpAndSettle();

      expect(find.byType(AccountVerificationView), findsOneWidget);
      expect(find.byType(VendorLandingView), findsNothing);

      controller.onClose();
    });

    testWidgets('invalid credentials do not navigate anywhere', (tester) async {
      final fake = _RecordingAuthDataSource()..loginOutcome = 'invalid';
      DataSource.instance = fake;

      final controller = _fillLoginController(tester);
      await _pumpLoginForm(tester, controller);

      await controller.loginAPI();
      await tester.pumpAndSettle();

      expect(find.byType(VendorLandingView), findsNothing);
      expect(find.byType(AccountVerificationView), findsNothing);

      controller.onClose();
    });

    testWidgets('an empty form is never submitted', (tester) async {
      final fake = _RecordingAuthDataSource();
      DataSource.instance = fake;

      final controller = VendorLoginController();
      await _pumpLoginForm(tester, controller);

      await controller.loginAPI();
      await tester.pumpAndSettle();

      expect(fake.capturedLoginBody, isNull);

      controller.onClose();
    });
  });

  group('VendorRegisterController', () {
    VendorRegisterController buildController() {
      final controller = VendorRegisterController();
      controller.nameController.text = 'Asha Patel';
      controller.businessNameController.text = 'Cool Air Services';
      controller.phoneController.text = '9812345678';
      controller.emailController.text = 'vendor@example.com';
      return controller;
    }

    Future<void> pumpRegisterForm(WidgetTester tester, VendorRegisterController controller) {
      return tester.pumpWidget(
        GetMaterialApp(
          home: Scaffold(
            body: Form(
              key: controller.formKey,
              child: Column(
                children: [
                  TextFormField(
                    controller: controller.nameController,
                    validator: (v) => controller.validateRequired(v, 'x'),
                  ),
                  TextFormField(
                    controller: controller.businessNameController,
                    validator: (v) => controller.validateRequired(v, 'x'),
                  ),
                  TextFormField(
                    controller: controller.phoneController,
                    validator: controller.validatePhone,
                  ),
                  TextFormField(
                    controller: controller.emailController,
                    validator: controller.validateEmail,
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    testWidgets('sends role from FlavorConfig, not a form field', (tester) async {
      final fake = _RecordingAuthDataSource();
      DataSource.instance = fake;

      final controller = buildController();
      await pumpRegisterForm(tester, controller);

      await controller.registerAPI();
      await tester.pumpAndSettle();

      expect(fake.capturedRegisterBody?['role'], 'vendor');
      expect(fake.capturedRegisterBody?['business_name'], 'Cool Air Services');
      expect(fake.capturedRegisterBody?['phone'], '9812345678');

      controller.onClose();
    });

    testWidgets('sends no password — it is set from the emailed link', (tester) async {
      final fake = _RecordingAuthDataSource();
      DataSource.instance = fake;

      final controller = buildController();
      await pumpRegisterForm(tester, controller);

      await controller.registerAPI();
      await tester.pumpAndSettle();

      expect(fake.capturedRegisterBody?.containsKey('password'), isFalse);
      expect(fake.capturedRegisterBody?.containsKey('password_confirmation'), isFalse);

      controller.onClose();
    });

    testWidgets('success goes to "Check your email", not verification or home', (tester) async {
      final fake = _RecordingAuthDataSource();
      DataSource.instance = fake;

      final controller = buildController();
      await pumpRegisterForm(tester, controller);

      await controller.registerAPI();
      await tester.pumpAndSettle();

      expect(find.byType(CheckEmailView), findsOneWidget);
      expect(find.byType(AccountVerificationView), findsNothing);
      expect(find.byType(VendorLandingView), findsNothing);

      final checkEmail = tester.widget<CheckEmailView>(find.byType(CheckEmailView));
      expect(checkEmail.email, 'vendor@example.com');
      expect(checkEmail.maskedEmail, 'v***@example.com');

      controller.onClose();
    });

    testWidgets('if the email could not be sent, stays on the form', (tester) async {
      final fake = _RecordingAuthDataSource()..registerEmailFails = true;
      DataSource.instance = fake;

      final controller = buildController();
      await pumpRegisterForm(tester, controller);

      await controller.registerAPI();
      await tester.pumpAndSettle();

      expect(find.byType(CheckEmailView), findsNothing);
      // Still filled in, so "Create account" can simply be tapped again.
      expect(controller.emailController.text, 'vendor@example.com');

      controller.onClose();
    });

    testWidgets('a duplicate-phone field error surfaces without crashing', (tester) async {
      final fake = _RecordingAuthDataSource()
        ..registerFieldErrors = {
          'phone': ['This phone number is already registered.'],
        };
      DataSource.instance = fake;

      final controller = buildController();
      await pumpRegisterForm(tester, controller);

      await controller.registerAPI();
      await tester.pumpAndSettle();

      expect(find.byType(AccountVerificationView), findsNothing);
      expect(find.byType(VendorLandingView), findsNothing);

      controller.onClose();
    });

    testWidgets('an invalid email is never submitted', (tester) async {
      final fake = _RecordingAuthDataSource();
      DataSource.instance = fake;

      final controller = buildController();
      controller.emailController.text = 'not-an-email';
      await pumpRegisterForm(tester, controller);

      await controller.registerAPI();
      await tester.pumpAndSettle();

      expect(fake.capturedRegisterBody, isNull);

      controller.onClose();
    });
  });
}

/// A minimal fake for the one must_change_password case, kept separate so
/// the main fake's response shape stays simple for every other test.
class _MustChangePasswordDataSource extends DataSource {
  @override
  Future<CommonResponse?> loginAPI({required Map<String, dynamic> body}) async {
    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'user': {
          'id': 1,
          'name': 'Asha Patel',
          'email': body['email'],
          'role': 'vendor',
          'must_change_password': true,
          // Approved, so this test isolates the password gate. Leaving it
          // unset would default to pending and the controller would route
          // to the verification screen instead — passing or failing for a
          // reason that has nothing to do with what is being tested.
          'approval_status': 'approved',
        },
        'token': '1|abc',
      },
      'error': null,
    }, statusCode: 200);
  }
}
