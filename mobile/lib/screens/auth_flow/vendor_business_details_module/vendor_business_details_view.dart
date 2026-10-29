import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';

import '../../../constants/app.export.dart';
import '../../../constants/constant.dart';
import 'vendor_business_details_controller.dart';

/// The vendor editing their own business profile (SPEC section 3.2).
///
/// Contact lives here too rather than on a second screen — see the
/// controller for why.
class VendorBusinessDetailsView extends StatelessWidget {
  const VendorBusinessDetailsView({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VendorBusinessDetailsController>(
      init: VendorBusinessDetailsController(),
      dispose: (_) => Get.delete<VendorBusinessDetailsController>(),
      builder: (controller) {
        if (controller.isLoading && controller.vendorMe == null) {
          return const Scaffold(
            backgroundColor: ServiceTokens.bg,
            body: Center(
              child: CupertinoActivityIndicator(color: ServiceTokens.accentBright),
            ),
          );
        }

        return Scaffold(
          backgroundColor: ServiceTokens.bg,
          resizeToAvoidBottomInset: true,
          body: ListView(
            padding: EdgeInsets.zero,
            children: [
              hero(controller),
              Padding(
                padding: EdgeInsets.fromLTRB(
                  16.getSize,
                  20.getSize,
                  16.getSize,
                  28.getSize,
                ),
                child: form(controller),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget hero(VendorBusinessDetailsController controller) {
    return VendorHero(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              VendorIconButton(
                icon: Icons.chevron_left,
                onTap: Get.back,
                tooltip: tr(StringRes.backToLogin),
              ),
              Expanded(
                child: BaseTextDMSans(
                  text: tr(StringRes.vendorBusinessDetailsTitle),
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: Colors.white,
                  textAlign: TextAlign.center,
                ),
              ),
              // Balances the back chip so the title sits truly centred.
              SizedBox(width: 42.getSize),
            ],
          ),
          20.heightSpacer,
          Row(
            children: [
              ProfilePhoto(
                initials: controller.initials,
                url: controller.avatarUrl,
                size: 62,
                onTap: controller.changePhoto,
              ),
              14.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: controller.businessNameController.text,
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                      textAlign: TextAlign.start,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    6.heightSpacer,
                    GestureDetector(
                      onTap: controller.changePhoto,
                      child: BaseTextDMSans(
                        text: tr(StringRes.changePhoto),
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: Colors.white,
                        textDecoration: TextDecoration.underline,
                        decorationColor: Colors.white,
                        textAlign: TextAlign.start,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget form(VendorBusinessDetailsController controller) {
    return Form(
      key: controller.formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          label(StringRes.businessName),
          8.heightSpacer,
          BaseTextField(
            controller: controller.businessNameController,
            hintText: tr(StringRes.enterBusinessName),
            isShowBorder: true,
            validateMode: controller.autoValidateMode,
            textInputAction: TextInputAction.next,
            validator: (v) =>
                controller.validateRequired(v, StringRes.enterBusinessName),
            // Redraws the hero's name and initials as it is typed.
            onChanged: (_) => controller.update(),
          ),
          18.heightSpacer,

          label(StringRes.ownerName),
          8.heightSpacer,
          BaseTextField(
            controller: controller.ownerNameController,
            hintText: tr(StringRes.enterOwnerName),
            isShowBorder: true,
            validateMode: controller.autoValidateMode,
            textInputAction: TextInputAction.next,
            validator: (v) =>
                controller.validateRequired(v, StringRes.enterOwnerName),
          ),
          18.heightSpacer,

          label(StringRes.address),
          8.heightSpacer,
          BaseTextField(
            controller: controller.addressController,
            hintText: tr(StringRes.vendorAddressHint),
            isShowBorder: true,
            textInputAction: TextInputAction.next,
          ),
          18.heightSpacer,

          label(StringRes.vendorCityLabel),
          8.heightSpacer,
          BaseTextField(
            controller: controller.cityController,
            hintText: tr(StringRes.vendorCityHint),
            isShowBorder: true,
            textInputAction: TextInputAction.next,
          ),
          18.heightSpacer,

          label(StringRes.vendorAboutLabel),
          8.heightSpacer,
          BaseTextField(
            controller: controller.aboutController,
            hintText: tr(StringRes.vendorAboutHint),
            isShowBorder: true,
            maxLines: 4,
            textInputAction: TextInputAction.newline,
          ),
          24.heightSpacer,

          // Contact, folded into this screen rather than given one of
          // its own.
          sectionLabel(tr(StringRes.vendorContactSection)),
          14.heightSpacer,

          label(StringRes.phone),
          8.heightSpacer,
          BaseTextField(
            controller: controller.phoneController,
            hintText: tr(StringRes.enterPhone),
            isShowBorder: true,
            textInputType: TextInputType.phone,
            validateMode: controller.autoValidateMode,
            textInputAction: TextInputAction.next,
            validator: controller.validatePhone,
          ),
          18.heightSpacer,

          label(StringRes.vendorEmailReadOnly),
          8.heightSpacer,
          BaseTextField(
            controller: controller.emailController,
            hintText: tr(StringRes.enterYourEmail),
            isShowBorder: true,
            textInputType: TextInputType.emailAddress,
            validateMode: controller.autoValidateMode,
            textInputAction: TextInputAction.next,
            validator: controller.validateEmail,
          ),
          if (controller.emailChanged) ...[
            18.heightSpacer,
            label(StringRes.passwordToChangeEmail),
            8.heightSpacer,
            BaseTextField(
              controller: controller.passwordController,
              hintText: tr(StringRes.passwordToChangeEmailHint),
              isShowBorder: true,
              isSecure: controller.obscurePassword,
              validateMode: controller.autoValidateMode,
              textInputAction: TextInputAction.done,
              validator: controller.validatePassword,
              suffixIcon: AuthVisibilityToggle(
                isObscured: controller.obscurePassword,
                onPressed: controller.togglePasswordVisibility,
              ),
            ),
            8.heightSpacer,
            BaseTextDMSans(
              text: tr(StringRes.passwordToChangeEmailNote),
              fontSize: 12,
              color: ServiceTokens.muted2,
              textAlign: TextAlign.start,
              maxLines: 3,
            ),
          ],
          28.heightSpacer,

          VendorPrimaryButton(
            label: tr(StringRes.vendorSaveChanges),
            enabled: !controller.isSaving,
            onTap: controller.saveAPI,
          ),
        ],
      ),
    );
  }

  Widget label(String key) {
    return BaseTextDMSans(
      text: key,
      fontSize: 14,
      fontWeight: FontWeight.w600,
      color: ServiceTokens.text,
      textAlign: TextAlign.start,
    ).tr();
  }

  Widget sectionLabel(String text) {
    return BaseTextDMSans(
      text: text.toUpperCase(),
      fontSize: 12,
      fontWeight: FontWeight.w600,
      letterSpacing: 0.9,
      color: ServiceTokens.muted2,
      textAlign: TextAlign.start,
    );
  }
}
