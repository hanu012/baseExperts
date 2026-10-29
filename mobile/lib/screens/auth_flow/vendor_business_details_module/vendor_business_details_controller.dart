import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// The vendor editing their own business profile (SPEC section 3.2).
///
/// Contact details are part of THIS screen rather than a second one: a
/// vendor thinks of "how customers find me" as one thing, and splitting a
/// six-field form across two screens only adds a hop. The old profile row
/// for them is gone.
///
/// The photo and the sign-in email are account-level, not business fields,
/// so they go through /user/avatar and PATCH /user/profile rather than
/// PATCH /vendors/me. Changing the email needs the current password (the
/// server refuses it otherwise); that field appears once the email differs.
class VendorBusinessDetailsController extends GetxController {
  final TextEditingController businessNameController = TextEditingController();
  final TextEditingController ownerNameController = TextEditingController();
  final TextEditingController phoneController = TextEditingController();
  final TextEditingController addressController = TextEditingController();
  final TextEditingController cityController = TextEditingController();
  final TextEditingController aboutController = TextEditingController();
  final TextEditingController emailController = TextEditingController();
  final TextEditingController passwordController = TextEditingController();

  GlobalKey<FormState> formKey = GlobalKey<FormState>();
  AutovalidateMode autoValidateMode = AutovalidateMode.disabled;

  VendorMeModel? vendorMe;
  bool isLoading = false;
  bool isSaving = false;
  bool obscurePassword = true;

  @override
  void onInit() {
    super.onInit();
    emailController.addListener(_onEmailChanged);
    fetchVendorMeAPI();
  }

  /// Fetches rather than taking the dashboard's copy: this screen is
  /// pushed and outlives individual tab switches, and editing a stale
  /// profile is how you overwrite a change made on another device.
  Future<void> fetchVendorMeAPI() async {
    isLoading = true;
    update();

    try {
      final response = await DataSource.instance.vendorMeAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      _fill(VendorMeModel.fromJson(response.data as Map<String, dynamic>));
    } catch (e) {
      if (kDebugMode) {
        print('Fetch business details error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      update();
    }
  }

  void _fill(VendorMeModel model) {
    vendorMe = model;
    businessNameController.text = model.businessName ?? '';
    ownerNameController.text = model.ownerName ?? '';
    phoneController.text = model.phone ?? '';
    addressController.text = model.address ?? '';
    cityController.text = model.city ?? '';
    aboutController.text = model.about ?? '';
    emailController.text = model.email ?? '';
  }

  String? get avatarUrl => vendorMe?.avatarUrl;

  bool get emailChanged =>
      emailController.text.trim().toLowerCase() !=
      (vendorMe?.email ?? '').trim().toLowerCase();

  bool _lastEmailChanged = false;

  /// Redraws only when the password field has to appear or go.
  void _onEmailChanged() {
    if (emailChanged != _lastEmailChanged) {
      _lastEmailChanged = emailChanged;
      if (!emailChanged) {
        passwordController.clear();
      }
      update();
    }
  }

  void togglePasswordVisibility() {
    obscurePassword = !obscurePassword;
    update();
  }

  Future<void> changePhoto() async {
    final user = await changeProfilePhoto(hasPhoto: (avatarUrl ?? '').isNotEmpty);

    if (user != null) {
      vendorMe?.avatarUrl = user.avatarUrl;
      update();
    }
  }

  /// Two letters for the avatar tile.
  String get initials {
    final parts = (businessNameController.text.isEmpty
            ? (vendorMe?.businessName ?? '')
            : businessNameController.text)
        .trim()
        .split(RegExp(r'\s+'))
        .where((part) => part.isNotEmpty)
        .toList();

    if (parts.isEmpty) {
      return '?';
    }

    if (parts.length == 1) {
      final one = parts.first;

      return (one.length >= 2 ? one.substring(0, 2) : one).toUpperCase();
    }

    return '${parts.first[0]}${parts[1][0]}'.toUpperCase();
  }

  Future<void> saveAPI() async {
    if (!(formKey.currentState?.validate() ?? false)) {
      autoValidateMode = AutovalidateMode.onUserInteraction;
      update();
      return;
    }

    isSaving = true;
    update();

    try {
      // The email first: if the password is wrong nothing is saved, rather
      // than the business details going through and the email silently not.
      if (emailChanged && !await _saveEmail()) {
        return;
      }

      final body = {
        'business_name': businessNameController.text.trim(),
        'owner_name': ownerNameController.text.trim(),
        'phone': phoneController.text.trim(),
        'address': addressController.text.trim(),
        'city': cityController.text.trim(),
        'about': aboutController.text.trim(),
      };

      Utils.showCircularProgressLottie(true);
      final response = await DataSource.instance.updateVendorMeAPI(body: body);
      Utils.showCircularProgressLottie(false);

      if (response == null || !response.isSuccess || response.data == null) {
        // The server reports a duplicate phone per-field, so mark that
        // rather than showing one generic banner.
        final fieldError = response?.fieldError('phone') ??
            response?.fieldError('business_name') ??
            response?.fieldError('owner_name');

        Utils.showToast(
          fieldError ?? response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      // Refilled from the response, not from what was typed: the server is
      // the authority on what was actually stored (trimming, casing).
      _fill(VendorMeModel.fromJson(response.data as Map<String, dynamic>));

      Utils.showToast(tr(StringRes.vendorProfileSaved));
      Get.back();
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Save business details error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isSaving = false;
      update();
    }
  }

  /// PATCH /user/profile with the new email and the current password.
  Future<bool> _saveEmail() async {
    Utils.showCircularProgressLottie(true);
    final response = await DataSource.instance.updateProfileAPI(body: {
      'email': emailController.text.trim(),
      'current_password': passwordController.text,
    });
    Utils.showCircularProgressLottie(false);

    if (response == null || !response.isSuccess || response.data == null) {
      Utils.showToast(
        response?.fieldError('current_password') ??
            response?.fieldError('email') ??
            response?.message ??
            tr(StringRes.somethingWentWrong),
        isError: true,
      );
      return false;
    }

    final user = UserModel.fromJson(response.data as Map<String, dynamic>);
    await Injector.setUserData(user, isFromEditProfile: true);
    vendorMe?.email = user.email;
    passwordController.clear();

    return true;
  }

  String? validateEmail(String? value) {
    final email = (value ?? '').trim();

    if (email.isEmpty) {
      return tr(StringRes.enterYourEmail);
    }

    if (!GetUtils.isEmail(email)) {
      return tr(StringRes.invalidEmail);
    }

    return null;
  }

  String? validatePassword(String? value) {
    if (emailChanged && (value ?? '').isEmpty) {
      return tr(StringRes.passwordToChangeEmailHint);
    }

    return null;
  }

  String? validateRequired(String? value, String messageKey) {
    if ((value ?? '').trim().isEmpty) {
      return tr(messageKey);
    }

    return null;
  }

  String? validatePhone(String? value) {
    final phone = (value ?? '').trim();

    if (phone.isEmpty) {
      return tr(StringRes.enterPhone);
    }

    if (phone.length < 10) {
      return tr(StringRes.invalidPhone);
    }

    return null;
  }

  @override
  void onClose() {
    businessNameController.dispose();
    ownerNameController.dispose();
    phoneController.dispose();
    addressController.dispose();
    cityController.dispose();
    aboutController.dispose();
    emailController.removeListener(_onEmailChanged);
    emailController.dispose();
    passwordController.dispose();
    super.onClose();
  }
}
