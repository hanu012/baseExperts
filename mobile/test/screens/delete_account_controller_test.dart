import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/delete_account_module/delete_account_controller.dart';
import 'package:service_marketplace/utils/injector.dart';

/// Fakes DELETE /user (SPEC section 4 item 10).
class _FakeDeleteAccountDataSource extends DataSource {
  bool shouldFail = false;
  List<String> capturedPasswords = [];

  @override
  Future<CommonResponse?> deleteAccountAPI({required String password}) async {
    capturedPasswords.add(password);

    if (shouldFail) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'INVALID_PASSWORD', 'message': 'Your password is incorrect.'},
      });
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {'message': 'Your account has been deleted.'},
      'error': null,
    });
  }
}

void main() {
  setUp(() async {
    Injector.accessToken = 'some-token';
  });

  testWidgets('deleteAccountAPI is a no-op when the form is invalid (empty password)', (
    tester,
  ) async {
    final fake = _FakeDeleteAccountDataSource();
    DataSource.instance = fake;
    var navigated = false;

    final controller = DeleteAccountController(loginViewBuilder: () => const Placeholder())
      ..navigateFn = (_) => navigated = true;

    // No Form widget is mounted in this unit test, so formKey.currentState
    // is null and `?? false` takes the invalid-form branch — the same
    // path a real empty submit takes.
    await controller.deleteAccountAPI();

    expect(fake.capturedPasswords, isEmpty);
    expect(navigated, isFalse);
    expect(Injector.accessToken, 'some-token');

    controller.onClose();
  });

  testWidgets(
    'a successful deletion clears the session and navigates to the login screen',
    (tester) async {
      final fake = _FakeDeleteAccountDataSource();
      DataSource.instance = fake;

      final formKey = GlobalKey<FormState>();
      var navigated = false;

      await tester.pumpWidget(
        MaterialApp(
          home: Form(key: formKey, child: const SizedBox()),
        ),
      );

      final controller = DeleteAccountController(loginViewBuilder: () => const Placeholder())
        ..formKey = formKey
        ..passwordController.text = 'correct-horse-battery'
        ..navigateFn = (_) => navigated = true;

      await controller.deleteAccountAPI();

      expect(fake.capturedPasswords, ['correct-horse-battery']);
      expect(Injector.accessToken, isEmpty);
      expect(navigated, isTrue);
      expect(controller.isSubmitting, isFalse);

      controller.onClose();
    },
  );

  testWidgets('the wrong password is rejected and does not clear the session or navigate', (
    tester,
  ) async {
    final fake = _FakeDeleteAccountDataSource()..shouldFail = true;
    DataSource.instance = fake;

    final formKey = GlobalKey<FormState>();
    var navigated = false;

    await tester.pumpWidget(
      MaterialApp(
        home: Form(key: formKey, child: const SizedBox()),
      ),
    );

    final controller = DeleteAccountController(loginViewBuilder: () => const Placeholder())
      ..formKey = formKey
      ..passwordController.text = 'wrong-password'
      ..navigateFn = (_) => navigated = true;

    await controller.deleteAccountAPI();

    expect(fake.capturedPasswords, ['wrong-password']);
    expect(Injector.accessToken, 'some-token');
    expect(navigated, isFalse);
    expect(controller.isSubmitting, isFalse);

    controller.onClose();
  });
}
