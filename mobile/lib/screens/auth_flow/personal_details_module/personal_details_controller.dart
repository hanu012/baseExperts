import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// Editing your own name, email, phone and photo — the "Personal details"
/// row on the customer and salesman profiles (the vendor edits the same
/// things on their business-details screen).
///
/// Everything goes through PATCH /user/profile and /user/avatar, which
/// answer with the updated user; that is stored straight into [Injector]
/// so every screen reading it agrees.
///
/// Changing the EMAIL asks for the current password as well: it is the
/// sign-in name and where a reset link goes, so the server refuses the
/// change without it. The field only appears once the email differs.
class PersonalDetailsController extends GetxController {
  PersonalDetailsController({this.phoneRequired = false});

  /// A salesman's phone is required (and unique); a customer's is optional.
  final bool phoneRequired;

  final TextEditingController nameController = TextEditingController();
  final TextEditingController emailController = TextEditingController();
  final TextEditingController phoneController = TextEditingController();
  final TextEditingController passwordController = TextEditingController();

  GlobalKey<FormState> formKey = GlobalKey<FormState>();
  AutovalidateMode autoValidateMode = AutovalidateMode.disabled;

  String? avatarUrl;
  bool isSaving = false;
  bool obscurePassword = true;

  /// The email as stored, to tell whether the typed one is a change.
  String _savedEmail = '';

  @override
  void onInit() {
    super.onInit();
    _fill(Injector.userData);
    emailController.addListener(_onEmailChanged);
    fetchUserAPI();
  }

  /// The stored copy may predate phone and photo being part of the user,
  /// so a fresh one is fetched; the form is usable meanwhile.
  Future<void> fetchUserAPI() async {
    try {
      final response = await DataSource.instance.userAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        return;
      }

      final user = UserModel.fromJson(response.data as Map<String, dynamic>);
      await Injector.setUserData(user, isFromEditProfile: true);

      // Left the screen while this was in flight.
      if (isClosed) {
        return;
      }

      _fill(user);
      update();
    } catch (e) {
      if (kDebugMode) {
        print('Fetch user error $e');
      }
    }
  }

  void _fill(UserModel? user) {
    if (user == null) {
      return;
    }

    nameController.text = user.name ?? '';
    emailController.text = user.email ?? '';
    phoneController.text = user.phone ?? '';
    avatarUrl = user.avatarUrl;
    _savedEmail = user.email ?? '';
  }

  String get initials => profileInitials(nameController.text);

  bool get emailChanged =>
      emailController.text.trim().toLowerCase() != _savedEmail.trim().toLowerCase();

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
      avatarUrl = user.avatarUrl;
      update();
    }
  }

  Future<void> saveAPI() async {
    if (isSaving) {
      return;
    }

    if (!(formKey.currentState?.validate() ?? false)) {
      autoValidateMode = AutovalidateMode.onUserInteraction;
      update();
      return;
    }

    isSaving = true;
    update();

    try {
      final body = <String, dynamic>{
        'name': nameController.text.trim(),
        'email': emailController.text.trim(),
        'phone': phoneController.text.trim(),
        if (emailChanged) 'current_password': passwordController.text,
      };

      Utils.showCircularProgressLottie(true);
      final response = await DataSource.instance.updateProfileAPI(body: body);
      Utils.showCircularProgressLottie(false);

      if (response == null || !response.isSuccess || response.data == null) {
        final fieldError = response?.fieldError('current_password') ??
            response?.fieldError('email') ??
            response?.fieldError('phone') ??
            response?.fieldError('name');

        Utils.showToast(
          fieldError ?? response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      final user = UserModel.fromJson(response.data as Map<String, dynamic>);
      await Injector.setUserData(user, isFromEditProfile: true);

      Utils.showToast(tr(StringRes.profileSaved));
      Get.back(result: true);
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Save personal details error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isSaving = false;
      update();
    }
  }

  String? validateName(String? value) {
    if ((value ?? '').trim().isEmpty) {
      return tr(StringRes.enterYourName);
    }

    return null;
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

  String? validatePhone(String? value) {
    final phone = (value ?? '').trim();

    if (phone.isEmpty) {
      return phoneRequired ? tr(StringRes.enterPhone) : null;
    }

    // Mirrors the server's rule: digits, optional leading +, spaces or
    // dashes, 7 to 19 characters.
    if (!RegExp(r'^\+?[0-9][0-9\s\-]{6,18}$').hasMatch(phone)) {
      return tr(StringRes.invalidPhone);
    }

    return null;
  }

  String? validatePassword(String? value) {
    if (emailChanged && (value ?? '').isEmpty) {
      return tr(StringRes.passwordToChangeEmailHint);
    }

    return null;
  }

  @override
  void onClose() {
    emailController.removeListener(_onEmailChanged);
    nameController.dispose();
    emailController.dispose();
    phoneController.dispose();
    passwordController.dispose();
    super.onClose();
  }
}
