import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'personal_details_controller.dart';

/// Photo, name, email and phone — see [PersonalDetailsController].
///
/// [footer] carries anything an app shows below the form but does not let
/// the user edit (a salesman's employee code and region).
class PersonalDetailsView extends StatelessWidget {
  const PersonalDetailsView({
    super.key,
    this.phoneRequired = false,
    this.footer,
  });

  final bool phoneRequired;
  final Widget? footer;

  @override
  Widget build(BuildContext context) {
    return GetBuilder<PersonalDetailsController>(
      init: PersonalDetailsController(phoneRequired: phoneRequired),
      dispose: (_) => Get.delete<PersonalDetailsController>(),
      builder: (controller) {
        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          resizeToAvoidBottomInset: true,
          body: ListView(
            padding: EdgeInsets.zero,
            children: [
              hero(controller),
              Padding(
                padding: EdgeInsets.fromLTRB(20.getSize, 24.getSize, 20.getSize, 30.getSize),
                child: form(controller),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget hero(PersonalDetailsController controller) {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [ServiceTokens.heroTop, ServiceTokens.accentDeep],
        ),
      ),
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: EdgeInsets.fromLTRB(12.getSize, 8.getSize, 12.getSize, 24.getSize),
          child: Column(
            children: [
              Row(
                children: [
                  IconButton(
                    onPressed: Get.back,
                    icon: const Icon(Icons.chevron_left, color: Colors.white),
                  ),
                  Expanded(
                    child: BaseTextDMSans(
                      text: tr(StringRes.personalDetails),
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                      textAlign: TextAlign.center,
                    ),
                  ),
                  // Balances the back button so the title sits centred.
                  SizedBox(width: 48.getSize),
                ],
              ),
              12.heightSpacer,
              ProfilePhoto(
                initials: controller.initials,
                url: controller.avatarUrl,
                onTap: controller.changePhoto,
              ),
              12.heightSpacer,
              GestureDetector(
                onTap: controller.changePhoto,
                child: BaseTextDMSans(
                  text: tr(StringRes.changePhoto),
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: Colors.white,
                  textDecoration: TextDecoration.underline,
                  decorationColor: Colors.white,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget form(PersonalDetailsController controller) {
    return Form(
      key: controller.formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AuthTextField(
            label: StringRes.fullName,
            hint: tr(StringRes.enterYourFullName),
            controller: controller.nameController,
            icon: Icons.person_outline,
            textCapitalization: TextCapitalization.words,
            textInputAction: TextInputAction.next,
            validateMode: controller.autoValidateMode,
            validator: controller.validateName,
          ),
          AuthTextField(
            label: StringRes.email,
            hint: tr(StringRes.enterYourEmail),
            controller: controller.emailController,
            icon: Icons.mail_outline,
            textInputType: TextInputType.emailAddress,
            textInputAction: TextInputAction.next,
            validateMode: controller.autoValidateMode,
            validator: controller.validateEmail,
          ),
          if (controller.emailChanged) ...[
            AuthTextField(
              label: StringRes.passwordToChangeEmail,
              hint: tr(StringRes.passwordToChangeEmailHint),
              controller: controller.passwordController,
              icon: Icons.lock_outline,
              isSecure: controller.obscurePassword,
              textInputAction: TextInputAction.next,
              validateMode: controller.autoValidateMode,
              validator: controller.validatePassword,
              suffixIcon: AuthVisibilityToggle(
                isObscured: controller.obscurePassword,
                onPressed: controller.togglePasswordVisibility,
              ),
            ),
          ],
          AuthTextField(
            label: phoneRequired ? StringRes.phone : StringRes.phoneOptional,
            hint: tr(StringRes.enterPhone),
            controller: controller.phoneController,
            icon: Icons.phone_outlined,
            textInputType: TextInputType.phone,
            textInputAction: TextInputAction.done,
            validateMode: controller.autoValidateMode,
            validator: controller.validatePhone,
            isLast: true,
          ),
          if (controller.emailChanged) ...[
            10.heightSpacer,
            BaseTextDMSans(
              text: tr(StringRes.passwordToChangeEmailNote),
              fontSize: 12,
              color: ServiceTokens.muted,
              textAlign: TextAlign.start,
              maxLines: 3,
            ),
          ],
          if (footer != null) ...[
            22.heightSpacer,
            footer!,
          ],
          26.heightSpacer,
          AuthPrimaryButton(
            label: StringRes.saveChanges,
            onPressed: controller.saveAPI,
          ),
        ],
      ),
    );
  }
}
