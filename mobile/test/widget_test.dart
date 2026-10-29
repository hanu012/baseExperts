import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/user_model.dart';
import 'package:service_marketplace/constants/color_res.dart';
import 'package:service_marketplace/widgets/base_services.dart';
import 'package:service_marketplace/widgets/base_button.dart';
import 'package:service_marketplace/widgets/base_text.dart';
import 'package:service_marketplace/widgets/base_textfield.dart';

void main() {
  group('CommonResponse maps the backend envelope', () {
    test('a success envelope exposes status and data', () {
      final response = CommonResponse.fromJson({
        'success': true,
        'data': {'user': {'id': 1}, 'token': 'abc'},
        'error': null,
      }, statusCode: 200);

      expect(response.status, isTrue);
      expect(response.isSuccess, isTrue);
      expect(response.data, isA<Map<String, dynamic>>());
      expect(response.errorCode, isNull);
    });

    test('an error envelope exposes code and message', () {
      final response = CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {
          'code': 'EMAIL_NOT_VERIFIED',
          'message': 'Please verify your email address before signing in.',
        },
      }, statusCode: 403);

      expect(response.isSuccess, isFalse);
      expect(response.errorCode, 'EMAIL_NOT_VERIFIED');
      expect(response.message, contains('verify your email'));
      expect(response.statusCode, 403);
    });

    test('validation errors are readable per field', () {
      final response = CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {
          'code': 'VALIDATION_FAILED',
          'message': 'The given data was invalid.',
          'fields': {
            'email': ['The email has already been taken.'],
            'role': ['Only vendor and customer accounts can be self-registered.'],
          },
        },
      }, statusCode: 422);

      expect(response.fieldError('email'), 'The email has already been taken.');
      expect(response.fieldError('role'), contains('vendor and customer'));
      expect(response.fieldError('nope'), isNull);
    });
  });

  group('UserModel', () {
    test('parses the wrapped auth payload and exposes the token', () {
      final user = UserModel.fromJson({
        'user': {
          'id': 7,
          'name': 'Asha Patel',
          'email': 'asha@example.com',
          'role': 'vendor',
          'email_verified_at': null,
        },
        'token': '1|abcdef',
      });

      expect(user.id, 7);
      expect(user.role, 'vendor');
      expect(user.isEmailVerified, isFalse);
      expect(user.authentication?.accessToken, '1|abcdef');
    });

    test('parses a bare user object with no token', () {
      final user = UserModel.fromJson({
        'id': 7,
        'name': 'Asha Patel',
        'email': 'asha@example.com',
        'role': 'customer',
        'email_verified_at': '2026-08-16T07:44:47+00:00',
      });

      expect(user.role, 'customer');
      expect(user.isEmailVerified, isTrue);
      expect(user.authentication, isNull);
    });
  });

  group('Theme', () {
    test('palette matches the values recorded in CLAUDE.md', () {
      // Light canvas, deep teal accent — the same teal family as the
      // Filament admin panel, which ends the split CLAUDE.md used to
      // record between the two products.
      expect(ColorRes.primaryColor, const Color(0xFF0F766E)); // teal-700
      expect(ColorRes.surfaceColor, const Color(0xFFFFFFFF));
      expect(ColorRes.backgroundColor, const Color(0xFFF6F7F9));
    });

    test('ServiceTokens shares the same accent as ColorRes', () {
      // Two palettes, one brand. They drifted apart once already; a
      // mismatch here is the first symptom.
      expect(ServiceTokens.accent, ColorRes.primaryColor);
      expect(ServiceTokens.bg, ColorRes.backgroundColor);
      expect(ServiceTokens.card, ColorRes.surfaceColor);
    });

    test('the page background is not pure white', () {
      // An off-white canvas is what lets a white card read as raised
      // against it — on pure white the cards would vanish.
      expect(ColorRes.backgroundColor, isNot(const Color(0xFFFFFFFF)));
      expect(ColorRes.surfaceColor, isNot(ColorRes.backgroundColor));
    });
  });

  group('Base widgets render', () {
    testWidgets('BaseTextDMSans renders its text', (tester) async {
      await tester.pumpWidget(
        MaterialApp(home: Scaffold(body: BaseTextDMSans(text: 'Full name'))),
      );

      expect(find.text('Full name'), findsOneWidget);
    });

    testWidgets('BaseRaisedButton renders its label and fires onPressed',
        (tester) async {
      var tapped = false;

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: BaseRaisedButton(
              buttonText: 'Create',
              onPressed: () => tapped = true,
            ),
          ),
        ),
      );

      expect(find.text('Create'), findsOneWidget);
      await tester.tap(find.byType(BaseRaisedButton));
      expect(tapped, isTrue);
    });

    testWidgets('BaseTextField shows its hint and reports changes',
        (tester) async {
      final controller = TextEditingController();
      String? seen;

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: BaseTextField(
              controller: controller,
              hintText: 'Enter your full name',
              onChanged: (value) => seen = value,
            ),
          ),
        ),
      );

      expect(find.text('Enter your full name'), findsOneWidget);
      await tester.enterText(find.byType(BaseTextField), 'Asha Patel');
      expect(seen, 'Asha Patel');
      expect(controller.text, 'Asha Patel');
    });
  });
}
