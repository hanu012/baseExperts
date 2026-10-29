import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/forgot_password_module/forgot_password_controller.dart';
import 'package:service_marketplace/screens/auth_flow/forgot_password_module/forgot_password_view.dart';

class _ResetRequestDataSource extends DataSource {
  Map<String, dynamic>? capturedBody;
  int calls = 0;

  /// Answer as the server does for an email with no account.
  bool unknownEmail = false;

  @override
  Future<CommonResponse?> passwordResetRequestAPI({
    required Map<String, dynamic> body,
  }) async {
    calls++;
    capturedBody = body;

    if (unknownEmail) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {
          'code': 'VALIDATION_FAILED',
          'message': 'No account found with this email.',
          'fields': {
            'email': ['No account found with this email.'],
          },
        },
      }, statusCode: 422);
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {'message': 'ok', 'method': 'laravel'},
      'error': null,
    });
  }
}

void main() {
  setUpAll(() {
    FlavorConfig.initialize(FlavorConfig.vendor);
  });

  tearDown(Get.reset);

  testWidgets('the screen offers an emailed reset link', (tester) async {
    DataSource.instance = _ResetRequestDataSource();

    await tester.pumpWidget(const GetMaterialApp(home: ForgotPasswordView()));
    await tester.pumpAndSettle();

    // Untranslated in tests, so the keys themselves are on screen.
    expect(find.text('forgotPasswordDescEmail'), findsOneWidget);
    expect(find.text('sendResetLink'), findsOneWidget);
  });

  testWidgets('prefills the email typed on the login screen', (tester) async {
    DataSource.instance = _ResetRequestDataSource();

    await tester.pumpWidget(
      const GetMaterialApp(home: ForgotPasswordView(initialEmail: 'asha@example.com')),
    );
    await tester.pumpAndSettle();

    expect(Get.find<ForgotPasswordController>().emailController.text, 'asha@example.com');
  });

  testWidgets('sends the trimmed email and returns to the login screen', (tester) async {
    final dataSource = _ResetRequestDataSource();
    DataSource.instance = dataSource;

    // Login stands in underneath, as in the app — the screen is pushed.
    await tester.pumpWidget(const GetMaterialApp(home: Scaffold(body: Text('login'))));
    Get.to(() => const ForgotPasswordView(initialEmail: '  asha@example.com '));
    await tester.pumpAndSettle();

    await Get.find<ForgotPasswordController>().sendResetRequestAPI();
    await tester.pumpAndSettle();

    expect(dataSource.capturedBody, {'email': 'asha@example.com'});
    expect(find.byType(ForgotPasswordView), findsNothing);
    expect(find.text('login'), findsOneWidget);
  });

  testWidgets('an invalid email is caught before any request', (tester) async {
    final dataSource = _ResetRequestDataSource();
    DataSource.instance = dataSource;

    await tester.pumpWidget(
      const GetMaterialApp(home: ForgotPasswordView(initialEmail: 'not-an-email')),
    );
    await tester.pumpAndSettle();

    await Get.find<ForgotPasswordController>().sendResetRequestAPI();
    await tester.pumpAndSettle();

    expect(dataSource.calls, 0);
    expect(find.byType(ForgotPasswordView), findsOneWidget);
  });

  testWidgets('an email with no account is shown under the field until edited', (tester) async {
    final dataSource = _ResetRequestDataSource()..unknownEmail = true;
    DataSource.instance = dataSource;

    await tester.pumpWidget(
      const GetMaterialApp(home: ForgotPasswordView(initialEmail: 'nobody@example.com')),
    );
    await tester.pumpAndSettle();

    final controller = Get.find<ForgotPasswordController>();
    await controller.sendResetRequestAPI();
    await tester.pumpAndSettle();

    expect(dataSource.calls, 1);
    // Stays on the screen, with the reason where the typo is.
    expect(find.byType(ForgotPasswordView), findsOneWidget);
    expect(find.text('No account found with this email.'), findsOneWidget);

    await tester.enterText(find.byType(EditableText), 'asha@example.com');
    await tester.pumpAndSettle();

    expect(find.text('No account found with this email.'), findsNothing);
  });
}
