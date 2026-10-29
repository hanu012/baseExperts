import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/user_model.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/personal_details_module/personal_details_controller.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_business_details_module/vendor_business_details_controller.dart';
import 'package:service_marketplace/utils/injector.dart';

/// PATCH /api/user/profile from the customer/salesman personal-details
/// screen and the vendor business-details screen.
class _FakeProfileDataSource extends DataSource {
  Map<String, dynamic> user = {
    'id': 7,
    'name': 'Ravi Shah',
    'email': 'ravi@example.com',
    'phone': '9800000001',
    'avatar_url': 'https://cdn.example.com/avatars/7/me.jpg',
    'role': 'customer',
  };

  /// Field errors to answer PATCH /user/profile with, or null to succeed.
  Map<String, List<String>>? profileErrors;

  final List<Map<String, dynamic>> profileBodies = [];
  final List<Map<String, dynamic>> vendorBodies = [];

  @override
  Future<CommonResponse?> userAPI() async =>
      CommonResponse.fromJson({'success': true, 'data': user, 'error': null});

  @override
  Future<CommonResponse?> updateProfileAPI({required Map<String, dynamic> body}) async {
    profileBodies.add(body);

    if (profileErrors != null) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'VALIDATION_FAILED', 'message': 'Invalid.', 'fields': profileErrors},
      }, statusCode: 422);
    }

    user = {...user, ...body}..remove('current_password');

    return CommonResponse.fromJson({'success': true, 'data': user, 'error': null});
  }

  @override
  Future<CommonResponse?> vendorMeAPI() async => CommonResponse.fromJson({
        'success': true,
        'data': _vendor(),
        'error': null,
      });

  @override
  Future<CommonResponse?> updateVendorMeAPI({required Map<String, dynamic> body}) async {
    vendorBodies.add(body);

    return CommonResponse.fromJson({'success': true, 'data': _vendor(), 'error': null});
  }

  Map<String, dynamic> _vendor() => {
        'vendor': {
          'id': 3,
          'business_name': 'Cool Air',
          'owner_name': 'Asha',
          'phone': '9811111111',
          'email': user['email'],
          'avatar_url': null,
        },
      };
}

Future<GlobalKey<FormState>> _mountForm(WidgetTester tester) async {
  final formKey = GlobalKey<FormState>();
  await tester.pumpWidget(
    GetMaterialApp(home: Scaffold(body: Form(key: formKey, child: const SizedBox()))),
  );
  return formKey;
}

void main() {
  setUp(() {
    Get.testMode = true;
    Injector.userData = null;
  });

  tearDown(Get.reset);

  test('the user model carries phone and photo and round-trips them', () {
    final user = UserModel.fromJson({
      'user': {
        'id': 1,
        'name': 'A',
        'email': 'a@example.com',
        'phone': '9800000001',
        'avatar_url': 'https://cdn.example.com/a.jpg',
      },
    });

    expect(user.phone, '9800000001');
    expect(user.avatarUrl, 'https://cdn.example.com/a.jpg');

    final again = UserModel.fromJson(user.toJson());
    expect(again.phone, '9800000001');
    expect(again.avatarUrl, 'https://cdn.example.com/a.jpg');
  });

  group('PersonalDetailsController', () {
    testWidgets('it fills the form from the signed-in user', (tester) async {
      DataSource.instance = _FakeProfileDataSource();

      final controller = PersonalDetailsController();
      await controller.fetchUserAPI();

      expect(controller.nameController.text, 'Ravi Shah');
      expect(controller.emailController.text, 'ravi@example.com');
      expect(controller.phoneController.text, '9800000001');
      expect(controller.avatarUrl, contains('avatars/7'));
      expect(Injector.userData?.phone, '9800000001');

      controller.onClose();
    });

    testWidgets('an unchanged email is saved without asking for the password', (tester) async {
      final fake = _FakeProfileDataSource();
      DataSource.instance = fake;
      final formKey = await _mountForm(tester);

      final controller = PersonalDetailsController()..formKey = formKey;
      await controller.fetchUserAPI();

      // Different case only — the server treats that as the same address.
      controller.emailController.text = 'RAVI@example.com';
      controller.nameController.text = 'Ravi S';
      expect(controller.emailChanged, isFalse);

      await controller.saveAPI();

      expect(fake.profileBodies.single.containsKey('current_password'), isFalse);
      expect(fake.profileBodies.single['name'], 'Ravi S');
      expect(Injector.userData?.name, 'Ravi S');

      controller.onClose();
    });

    testWidgets('a changed email sends the current password', (tester) async {
      final fake = _FakeProfileDataSource();
      DataSource.instance = fake;
      final formKey = await _mountForm(tester);

      final controller = PersonalDetailsController()..formKey = formKey;
      await controller.fetchUserAPI();

      controller.emailController.text = 'new@example.com';
      controller.passwordController.text = 'Current#Pass2026';
      expect(controller.emailChanged, isTrue);

      await controller.saveAPI();

      expect(fake.profileBodies.single['email'], 'new@example.com');
      expect(fake.profileBodies.single['current_password'], 'Current#Pass2026');
      expect(Injector.userData?.email, 'new@example.com');

      controller.onClose();
    });

    testWidgets('a refused save leaves the stored user alone', (tester) async {
      final fake = _FakeProfileDataSource();
      DataSource.instance = fake;
      final formKey = await _mountForm(tester);

      final controller = PersonalDetailsController()..formKey = formKey;
      await controller.fetchUserAPI();

      fake.profileErrors = {
        'current_password': ['Enter your current password to change your email.'],
      };
      controller.emailController.text = 'new@example.com';
      controller.passwordController.text = 'wrong';

      await controller.saveAPI();
      await tester.pump(const Duration(seconds: 4));

      expect(Injector.userData?.email, 'ravi@example.com');
      expect(controller.isSaving, isFalse);

      controller.onClose();
    });

    test('the phone is optional for a customer and required for a salesman', () {
      final customer = PersonalDetailsController();
      final salesman = PersonalDetailsController(phoneRequired: true);

      expect(customer.validatePhone(''), isNull);
      expect(salesman.validatePhone(''), isNotNull);
      expect(customer.validatePhone('call me'), isNotNull);
      expect(customer.validatePhone('+91 98000-00001'), isNull);

      customer.onClose();
      salesman.onClose();
    });

    test('the password is only required once the email changes', () {
      final controller = PersonalDetailsController();
      Injector.userData = UserModel.fromJson({'id': 1, 'email': 'a@example.com'});
      controller.onInit();

      controller.emailController.text = 'a@example.com';
      expect(controller.validatePassword(''), isNull);

      controller.emailController.text = 'b@example.com';
      expect(controller.validatePassword(''), isNotNull);
      expect(controller.validatePassword('secret'), isNull);

      controller.onClose();
    });
  });

  group('VendorBusinessDetailsController', () {
    testWidgets('a wrong password stops the save before the business details', (tester) async {
      final fake = _FakeProfileDataSource();
      DataSource.instance = fake;
      final formKey = await _mountForm(tester);

      final controller = VendorBusinessDetailsController()..formKey = formKey;
      await controller.fetchVendorMeAPI();

      fake.profileErrors = {
        'current_password': ['Enter your current password to change your email.'],
      };
      controller.emailController.text = 'new@example.com';
      controller.passwordController.text = 'wrong';

      await controller.saveAPI();
      await tester.pump(const Duration(seconds: 4));

      expect(fake.profileBodies.single['current_password'], 'wrong');
      expect(fake.vendorBodies, isEmpty);

      controller.onClose();
    });

    testWidgets('an unchanged email saves only the business details', (tester) async {
      final fake = _FakeProfileDataSource();
      DataSource.instance = fake;
      final formKey = await _mountForm(tester);

      final controller = VendorBusinessDetailsController()..formKey = formKey;
      await controller.fetchVendorMeAPI();

      expect(controller.emailController.text, 'ravi@example.com');
      await controller.saveAPI();

      expect(fake.profileBodies, isEmpty);
      expect(fake.vendorBodies, hasLength(1));
      expect(fake.vendorBodies.single.containsKey('email'), isFalse);

      controller.onClose();
    });

    testWidgets('a changed email is saved first, then the business details', (tester) async {
      final fake = _FakeProfileDataSource();
      DataSource.instance = fake;
      final formKey = await _mountForm(tester);

      final controller = VendorBusinessDetailsController()..formKey = formKey;
      await controller.fetchVendorMeAPI();

      controller.emailController.text = 'shop@example.com';
      controller.passwordController.text = 'Current#Pass2026';

      await controller.saveAPI();

      expect(fake.profileBodies.single, {
        'email': 'shop@example.com',
        'current_password': 'Current#Pass2026',
      });
      expect(fake.vendorBodies, hasLength(1));
      expect(Injector.userData?.email, 'shop@example.com');

      controller.onClose();
    });
  });
}
