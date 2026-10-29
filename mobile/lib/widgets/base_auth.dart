import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../constants/color_res.dart';
import '../constants/constant.dart';
import 'base_button.dart';
import 'base_text.dart';
import 'base_textfield.dart';

/// The shared visual system for the sign-in / sign-up screens.
///
/// Every auth screen is assembled from these five pieces, so Login and Register
/// cannot drift apart: change a radius or a spacing here and all five screens
/// move together.
///
/// Deliberately separate from [Utils.authLayout], which is still used by
/// add_vendor, select_plan, select_services and change_password. Those are not
/// auth screens and were not in scope, so they keep the old chrome rather than
/// being restyled by a side effect.
///
/// Sizing constants live in [_AuthTokens] rather than being sprinkled through
/// the widgets — the design language is a handful of numbers, and they should
/// be readable in one place.
class _AuthTokens {
  /// Horizontal gutter for everything on the screen.
  static double get gutter => 24.getSize;

  /// Between a field and the next label.
  static double get fieldGap => 18.getSize;

  // Field and button radius, field padding and button padding all match the
  // provider app's BaseTextField / BaseRaisedButton.

  /// Corner radius on inputs.
  static double get fieldRadius => 12.getSize;

  /// Corner radius on the primary button and the back chip's square-ish kin.
  static double get buttonRadius => 12.getSize;

  /// Vertical padding inside an input.
  static double get fieldPadding => 14.getSize;

  /// Vertical padding inside the primary button.
  static double get buttonPadding => 14.getSize;

  /// Minimum tap target for the back chip and the password eye.
  static double get tapTarget => 46.getSize;

  /// Corner radius on the form card and the tip callout.
  static double get cardRadius => 24.getSize;
}

/// App-icon tile, product name and strapline above the form card.
///
/// Each flavour supplies its own copy, so the three apps share one layout
/// without claiming to be the same product.
class AuthBrandHeader extends StatelessWidget {
  const AuthBrandHeader({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
  });

  final IconData icon;

  /// Translation key.
  final String title;

  /// Translation key.
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    // Full width so `crossAxisAlignment: center` has room to actually
    // centre against. AuthScaffold's column is start-aligned, so without
    // this the header shrink-wraps its content and hugs the left edge.
    return SizedBox(
      width: double.infinity,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Container(
            height: 78.getSize,
            width: 78.getSize,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [ColorRes.primaryColorLight, ColorRes.primaryColorDark],
              ),
              borderRadius: BorderRadius.circular(24.getSize),
              boxShadow: [
                BoxShadow(
                  color: ColorRes.primaryColor.withValues(alpha: 0.45),
                  blurRadius: 32.getSize,
                  offset: Offset(0, 12.getSize),
                ),
              ],
            ),
            child: Icon(icon, size: 38.getSize, color: ColorRes.whiteColor),
          ),
          18.heightSpacer,
          BaseTextDMSans(
            text: title,
            fontSize: 18,
            fontWeight: FontWeight.w600,
            color: ColorRes.secondaryColor,
            textAlign: TextAlign.center,
            maxLines: 2,
          ).tr(),
          if (subtitle != null) ...[
            6.heightSpacer,
            BaseTextDMSans(
              text: subtitle!,
              fontSize: 12,
              fontWeight: FontWeight.w400,
              color: ColorRes.grayColor,
              textAlign: TextAlign.center,
              maxLines: 2,
            ).tr(),
          ],
        ],
      ),
    );
  }
}

/// "Remember me" plus an inline action on the right.
class AuthExtrasRow extends StatelessWidget {
  const AuthExtrasRow({
    super.key,
    required this.checkboxLabel,
    required this.checked,
    required this.onCheckedChanged,
    this.actionLabel,
    this.onAction,
  });

  /// Translation key.
  final String checkboxLabel;

  final bool checked;
  final ValueChanged<bool> onCheckedChanged;

  /// Translation key for the right-hand link.
  final String? actionLabel;

  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Flexible(
          child: GestureDetector(
            onTap: () => onCheckedChanged(!checked),
            behavior: HitTestBehavior.opaque,
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                AnimatedContainer(
                  duration: const Duration(milliseconds: 120),
                  height: 22.getSize,
                  width: 22.getSize,
                  decoration: BoxDecoration(
                    color: checked
                        ? ColorRes.primaryColor
                        : ColorRes.transparent,
                    borderRadius: BorderRadius.circular(7.getSize),
                    border: Border.all(
                      color: checked
                          ? ColorRes.primaryColor
                          : ColorRes.borderColor,
                      width: 1.5,
                    ),
                  ),
                  child: checked
                      ? Icon(
                          Icons.check,
                          size: 15.getSize,
                          color: ColorRes.whiteColor,
                        )
                      : null,
                ),
                10.widthSpacer,
                Flexible(
                  child: BaseTextDMSans(
                    text: checkboxLabel,
                    fontSize: 12,
                    fontWeight: FontWeight.w500,
                    color: ColorRes.grayColor,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ).tr(),
                ),
              ],
            ),
          ),
        ),
        if (actionLabel != null && onAction != null) ...[
          8.widthSpacer,
          Flexible(
            child: GestureDetector(
              onTap: onAction,
              child: BaseTextDMSans(
                text: actionLabel!,
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: ColorRes.primaryColorLight,
                textAlign: TextAlign.end,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ).tr(),
            ),
          ),
        ],
      ],
    );
  }
}

/// The muted callout under the card — the first-login temp-password note.
class AuthTipBox extends StatelessWidget {
  const AuthTipBox({super.key, required this.message, this.icon});

  /// Translation key.
  final String message;

  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceColor.withValues(alpha: 0.6),
        borderRadius: BorderRadius.circular(_AuthTokens.cardRadius),
        border: Border.all(color: ColorRes.borderColor),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            icon ?? Icons.lightbulb_outline,
            size: 18.getSize,
            color: ColorRes.primaryColorLight,
          ),
          12.widthSpacer,
          Expanded(
            child: BaseTextDMSans(
              text: message,
              fontSize: 12,
              fontWeight: FontWeight.w400,
              color: ColorRes.grayColor,
              textAlign: TextAlign.start,
              lineHeight: 1.45,
              maxLines: 4,
            ).tr(),
          ),
        ],
      ),
    );
  }
}

/// Page chrome: ambient glow, back chip, title block, then the caller's form.
///
/// The whole page scrolls as one column with no [Expanded] or [Spacer]
/// anywhere, which is what makes it overflow-proof: content taller than the
/// viewport scrolls instead of asserting, on a small phone and with the
/// keyboard open alike.
///
/// The primary button sits inside the scroll flow rather than in the
/// Scaffold's `bottomNavigationBar` (where these screens previously kept it).
/// A bottom bar has to be manually padded by `viewInsets.bottom` to dodge the
/// keyboard and still ends up pinned over the fields it belongs to; in the
/// flow it simply follows the last field, which is also where the reference
/// design puts it.
class AuthScaffold extends StatelessWidget {
  const AuthScaffold({
    super.key,
    required this.title,
    required this.subtitle,
    required this.formKey,
    required this.fields,
    required this.primaryAction,
    this.showBack = false,
    this.onBack,
    this.footer,
    this.brandIcon,
    this.brandTitle,
    this.brandSubtitle,
    this.extras,
    this.tip,
    this.bottomLink,
  });

  /// The last thing on the screen, under [footer] — "Forgot password?" on
  /// the sign-in screens. Kept apart from "Remember me" so the two are not
  /// crowded onto one line, and down with the other account links.
  final Widget? bottomLink;

  /// Glyph for the app-icon tile above the card. Supplying it (with
  /// [brandTitle]) turns on the branded header; screens that omit it keep
  /// the plainer stacked layout.
  final IconData? brandIcon;

  /// Translation key for the product name under the icon — "Salesman
  /// Portal". Each flavour passes its own, so the three apps share one
  /// layout without pretending to be the same product.
  final String? brandTitle;

  /// Translation key for the line under it.
  final String? brandSubtitle;

  /// Row between the last field and the button — "Remember me".
  final Widget? extras;

  /// Callout under the card, e.g. the first-login temp-password note.
  final Widget? tip;

  /// Translation key for the screen's headline.
  final String title;

  /// Translation key for the line under it.
  final String subtitle;

  final GlobalKey<FormState> formKey;

  /// The screen's inputs, in order. Usually [AuthTextField]s.
  final List<Widget> fields;

  /// The submit button — [AuthPrimaryButton].
  final Widget primaryAction;

  /// Shows the circular back chip. Off for a flavour's first screen, where
  /// there is nothing behind it to return to.
  final bool showBack;

  /// Where back goes, for a screen that replaced the one before it rather
  /// than being pushed over it (register ↔ login swap with Get.off). There
  /// is then nothing to pop, so Get.back() would do nothing. Also applied to
  /// the system back button/gesture, which would otherwise close the app.
  final VoidCallback? onBack;

  /// The "Don't have an account? Register" line, when the screen has one.
  final Widget? footer;

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: onBack == null,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) {
          onBack?.call();
        }
      },
      child: _scaffold(),
    );
  }

  Widget _scaffold() {
    return Scaffold(
      backgroundColor: ColorRes.backgroundColor,
      resizeToAvoidBottomInset: true,
      body: Stack(
        children: [
          const _AmbientGlow(),
          SafeArea(
            child: SingleChildScrollView(
              // No viewInsets padding here on purpose. `resizeToAvoidBottomInset`
              // already shrinks the body by the keyboard's height, and this
              // build context sits *above* the Scaffold — so reading
              // MediaQuery.viewInsets here would count the keyboard a second
              // time and leave a keyboard-sized void under the form.
              padding: EdgeInsets.only(
                left: _AuthTokens.gutter,
                right: _AuthTokens.gutter,
                top: 8.getSize,
                bottom: 32.getSize,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (showBack) ...[
                    AuthBackChip(onTap: onBack),
                    20.heightSpacer,
                  ] else
                    8.heightSpacer,
                  if (brandTitle != null) ...[
                    AuthBrandHeader(
                      icon: brandIcon ?? Icons.storefront_outlined,
                      title: brandTitle!,
                      subtitle: brandSubtitle,
                    ),
                    26.heightSpacer,
                  ],
                  // The form sits in a raised card so the branded header
                  // above it reads as product identity rather than as part
                  // of the form.
                  Container(
                    width: double.infinity,
                    padding: EdgeInsets.all(22.getSize),
                    decoration: BoxDecoration(
                      color: ColorRes.surfaceColor,
                      borderRadius: BorderRadius.circular(
                        _AuthTokens.cardRadius,
                      ),
                      border: Border.all(color: ColorRes.borderColor),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.35),
                          blurRadius: 30.getSize,
                          offset: Offset(0, 12.getSize),
                        ),
                      ],
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        BaseTextDMSans(
                          text: title,
                          fontSize: 18,
                          fontWeight: FontWeight.w600,
                          color: ColorRes.secondaryColor,
                          textAlign: TextAlign.start,
                          maxLines: 2,
                        ).tr(),
                        10.heightSpacer,
                        BaseTextDMSans(
                          text: subtitle,
                          fontSize: 12,
                          fontWeight: FontWeight.w400,
                          color: ColorRes.grayColor,
                          textAlign: TextAlign.start,
                          lineHeight: 1.45,
                          maxLines: 3,
                        ).tr(),
                        26.heightSpacer,
                        Form(
                          key: formKey,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: fields,
                          ),
                        ),
                        if (extras != null) ...[16.heightSpacer, extras!],
                        24.heightSpacer,
                        primaryAction,
                      ],
                    ),
                  ),
                  if (tip != null) ...[16.heightSpacer, tip!],
                  if (footer != null) ...[
                    20.heightSpacer,
                    Center(child: footer!),
                  ],
                  if (bottomLink != null) ...[
                    // Tight under the footer, as one group of account links.
                    footer != null ? 2.heightSpacer : 20.heightSpacer,
                    Center(child: bottomLink!),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A soft brand-coloured wash behind the title block.
///
/// This is the reference design's ambient glow, in teal rather than violet so
/// the apps still read as the same product as the Filament admin panel. Purely
/// decorative, so it is wrapped in [IgnorePointer] — it must never eat a tap
/// meant for the field underneath it.
class _AmbientGlow extends StatelessWidget {
  const _AmbientGlow();

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        height: 320.getSize,
        width: double.infinity,
        decoration: BoxDecoration(
          gradient: RadialGradient(
            center: const Alignment(-0.5, -0.9),
            radius: 1.1,
            colors: [
              ColorRes.primaryColor.withValues(alpha: 0.16),
              ColorRes.backgroundColor.withValues(alpha: 0.0),
            ],
          ),
        ),
      ),
    );
  }
}

/// Circular back button, sized to a comfortable 46pt tap target.
class AuthBackChip extends StatelessWidget {
  const AuthBackChip({super.key, this.onTap});

  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ColorRes.transparent,
      child: InkWell(
        onTap: onTap ?? () => Get.back(),
        borderRadius: BorderRadius.circular(_AuthTokens.tapTarget),
        child: Container(
          height: _AuthTokens.tapTarget,
          width: _AuthTokens.tapTarget,
          decoration: BoxDecoration(
            color: ColorRes.surfaceColor,
            shape: BoxShape.circle,
            border: Border.all(color: ColorRes.borderColor, width: 1.getSize),
          ),
          child: Icon(
            Icons.arrow_back,
            size: 20.getSize,
            color: ColorRes.secondaryColor,
          ),
        ),
      ),
    );
  }
}

/// Label + input, the only field widget these screens use.
///
/// Every parameter that the previous [BaseTextField] call sites passed is
/// carried through unchanged — controller, validator, autovalidate mode,
/// keyboard type, input action, capitalisation, submit handler — so validation
/// and error text behave exactly as before. Only the presentation is new.
class AuthTextField extends StatelessWidget {
  const AuthTextField({
    super.key,
    required this.label,
    required this.hint,
    required this.controller,
    required this.icon,
    this.validator,
    this.validateMode,
    this.textInputType,
    this.textInputAction,
    this.textCapitalization = TextCapitalization.none,
    this.onFieldSubmitted,
    this.isSecure = false,
    this.suffixIcon,
    this.isLast = false,
  });

  /// Translation key shown above the field.
  final String label;

  /// Already-translated placeholder text.
  final String hint;

  final TextEditingController controller;

  /// Leading glyph inside the field.
  final IconData icon;

  final String? Function(String?)? validator;
  final AutovalidateMode? validateMode;
  final TextInputType? textInputType;
  final TextInputAction? textInputAction;
  final TextCapitalization textCapitalization;
  final void Function(String)? onFieldSubmitted;
  final bool isSecure;
  final Widget? suffixIcon;

  /// Suppresses the trailing gap on the final field so the spacing before the
  /// button is controlled by [AuthScaffold] alone.
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        BaseTextDMSans(
          text: label,
          fontSize: 12,
          fontWeight: FontWeight.w500,
          color: ColorRes.secondaryColor,
          textAlign: TextAlign.start,
        ).tr(),
        8.heightSpacer,
        BaseTextField(
          controller: controller,
          hintText: hint,
          isShowBorder: true,
          isSecure: isSecure,
          validator: validator,
          validateMode: validateMode,
          textInputType: textInputType,
          textInputAction: textInputAction,
          textCapitalization: textCapitalization,
          onFieldSubmitted: onFieldSubmitted,
          fillColor: ColorRes.surfaceColor,
          borderColor: ColorRes.borderColor,
          focusBorderColor: ColorRes.primaryColor,
          borderRadius: _AuthTokens.fieldRadius,
          borderWidth: 1.2,
          contentPadding: EdgeInsets.symmetric(
            horizontal: 12.getSize,
            vertical: _AuthTokens.fieldPadding,
          ),
          prefixIcon: Icon(icon, size: 20.getSize, color: ColorRes.grayColor),
          suffixIcon: suffixIcon,
        ),
        if (!isLast) SizedBox(height: _AuthTokens.fieldGap),
      ],
    );
  }
}

/// The eye toggle used by every password field, so the three screens that have
/// one cannot end up with three slightly different icons.
class AuthVisibilityToggle extends StatelessWidget {
  const AuthVisibilityToggle({
    super.key,
    required this.isObscured,
    required this.onPressed,
  });

  final bool isObscured;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      onPressed: onPressed,
      splashRadius: 22.getSize,
      icon: Icon(
        isObscured ? Icons.visibility_off_outlined : Icons.visibility_outlined,
        color: ColorRes.grayColor,
        size: 20.getSize,
      ),
    );
  }
}

/// Full-width gradient submit button.
///
/// Wraps [BaseRaisedButton] rather than replacing it — the gradient lives on
/// the container, the button itself is transparent, so the existing label
/// styling, tap feedback and `onPressed` contract are untouched.
class AuthPrimaryButton extends StatelessWidget {
  const AuthPrimaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.trailingIcon,
  });

  /// Translation key for the button text.
  final String label;

  final VoidCallback onPressed;

  /// Optional arrow after the label, for a button that advances a flow
  /// rather than simply submitting a form.
  final IconData? trailingIcon;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(_AuthTokens.buttonRadius),
        // primaryColor → primaryColorDark, never starting at
        // primaryColorLight: white text on violet-400 is 2.7:1 and fails AA.
        // This sweep keeps the label between 4.2:1 and 7.0:1 end to end.
        gradient: LinearGradient(
          begin: Alignment.centerLeft,
          end: Alignment.centerRight,
          colors: [ColorRes.primaryColor, ColorRes.primaryColorDark],
        ),
        boxShadow: [
          BoxShadow(
            color: ColorRes.primaryColor.withValues(alpha: 0.28),
            blurRadius: 20.getSize,
            offset: Offset(0, 8.getSize),
          ),
        ],
      ),
      child: trailingIcon == null
          ? BaseRaisedButton(
              onPressed: onPressed,
              buttonText: label,
              buttonColor: ColorRes.transparent,
              textColor: ColorRes.whiteColor,
              borderRadius: _AuthTokens.buttonRadius,
              buttonVerticalPadding: _AuthTokens.buttonPadding,
              fontSize: 12.getFontSize,
              fontWeight: FontWeight.w600,
            )
          // BaseRaisedButton takes a label string, not a child, so the
          // icon variant is built here rather than by widening it.
          : Material(
              color: ColorRes.transparent,
              child: InkWell(
                onTap: onPressed,
                borderRadius: BorderRadius.circular(_AuthTokens.buttonRadius),
                child: Padding(
                  padding: EdgeInsets.symmetric(vertical: _AuthTokens.buttonPadding),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Flexible(
                        child: BaseTextDMSans(
                          text: label,
                          fontSize: 12.getFontSize,
                          fontWeight: FontWeight.w600,
                          color: ColorRes.whiteColor,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ).tr(),
                      ),
                      10.widthSpacer,
                      Icon(
                        trailingIcon,
                        size: 18.getSize,
                        color: ColorRes.whiteColor,
                      ),
                    ],
                  ),
                ),
              ),
            ),
    );
  }
}

/// The "Don't have an account? Register" line.
///
/// Kept as a single tappable row so the whole line is a comfortable target,
/// with only the action half carrying brand colour and weight.
class AuthFooterLink extends StatelessWidget {
  const AuthFooterLink({
    super.key,
    required this.promptKey,
    required this.actionKey,
    required this.onTap,
  });

  /// Translation key for the muted lead-in text.
  final String promptKey;

  /// Translation key for the emphasised action word.
  final String actionKey;

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ColorRes.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(8.getSize),
        child: Padding(
          padding: EdgeInsets.symmetric(
            horizontal: 8.getSize,
            vertical: 10.getSize,
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Flexible(
                child: BaseTextDMSans(
                  text: promptKey,
                  fontSize: 12,
                  fontWeight: FontWeight.w400,
                  color: ColorRes.grayColor,
                ).tr(),
              ),
              6.widthSpacer,
              Flexible(
                child: BaseTextDMSans(
                  // primaryColorLight, not primaryColor: at 13pt this is body
                  // text, so it needs 4.5:1. violet-500 lands at 4.4:1 on the
                  // canvas; violet-400 clears it at 7.0:1.
                  text: actionKey,
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: ColorRes.primaryColorLight,
                ).tr(),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A single centred link — "Forgot password?" at the foot of a sign-in
/// screen. Styled like [AuthFooterLink]'s action half.
class AuthTextLink extends StatelessWidget {
  const AuthTextLink({super.key, required this.labelKey, required this.onTap});

  /// Translation key.
  final String labelKey;

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ColorRes.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(8.getSize),
        child: Padding(
          padding: EdgeInsets.symmetric(horizontal: 8.getSize, vertical: 10.getSize),
          child: BaseTextDMSans(
            text: labelKey,
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: ColorRes.primaryColorLight,
          ).tr(),
        ),
      ),
    );
  }
}

// ── Gradient hero ─────────────────────────────────────────────────────────

/// The violet block a task-style auth screen opens with: an icon tile, a
/// headline and one line of explanation.
///
/// Used where the screen is a job to finish rather than a form to fill in
/// — the forced password change, for one. The sign-in pair keep
/// [AuthBrandHeader], which leads with the product rather than the task.
class AuthGradientHero extends StatelessWidget {
  const AuthGradientHero({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.trailing,
  });

  final IconData icon;
  final String title;
  final String? subtitle;

  /// Optional control pinned to the top-right — a sign-out pill, usually.
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            ColorRes.primaryColor,
            ColorRes.primaryColorDark,
          ],
        ),
      ),
      child: Stack(
        children: [
          // The faint ring in the top-right corner, clipped by the Stack.
          Positioned(
            right: -70.getSize,
            top: -80.getSize,
            child: Container(
              height: 240.getSize,
              width: 240.getSize,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white.withValues(alpha: 0.12)),
              ),
            ),
          ),
          SafeArea(
            bottom: false,
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                _AuthTokens.gutter,
                14.getSize,
                _AuthTokens.gutter,
                24.getSize,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        height: 46.getSize,
                        width: 46.getSize,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: 0.18),
                          borderRadius: BorderRadius.circular(14.getSize),
                          border: Border.all(
                            color: Colors.white.withValues(alpha: 0.16),
                          ),
                        ),
                        child: Icon(icon, size: 21.getSize, color: Colors.white),
                      ),
                      const Spacer(),
                      ?trailing,
                    ],
                  ),
                  18.heightSpacer,
                  BaseTextDMSans(
                    text: title,
                    fontSize: 18,
                    fontWeight: FontWeight.w600,
                    color: Colors.white,
                    textAlign: TextAlign.start,
                  ),
                  if (subtitle != null) ...[
                    7.heightSpacer,
                    BaseTextDMSans(
                      text: subtitle!,
                      fontSize: 12,
                      color: Colors.white.withValues(alpha: 0.84),
                      textAlign: TextAlign.start,
                      maxLines: 3,
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A titled card of live tick-offs — "your password should have ...".
///
/// The ticks reflect what is currently typed rather than being a static
/// list, so the rule that is still failing is visible before the form is
/// submitted rather than after it is rejected.
class AuthChecklistCard extends StatelessWidget {
  const AuthChecklistCard({
    super.key,
    required this.title,
    required this.items,
  });

  final String title;

  /// Label → whether it is currently satisfied.
  final Map<String, bool> items;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ColorRes.surfaceColor,
        borderRadius: BorderRadius.circular(_AuthTokens.fieldRadius),
        border: Border.all(color: ColorRes.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          BaseTextDMSans(
            text: title.toUpperCase(),
            fontSize: 12,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.9,
            color: ColorRes.grayColor,
            textAlign: TextAlign.start,
          ),
          12.heightSpacer,
          for (final entry in items.entries)
            Padding(
              padding: EdgeInsets.only(bottom: 8.getSize),
              child: Row(
                children: [
                  Icon(
                    entry.value ? Icons.check_circle : Icons.check,
                    size: 15.getSize,
                    color: entry.value
                        ? ColorRes.successColor
                        : ColorRes.grayColor,
                  ),
                  9.widthSpacer,
                  Expanded(
                    child: BaseTextDMSans(
                      text: entry.key,
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                      color: entry.value
                          ? ColorRes.secondaryColor
                          : ColorRes.grayColor,
                      textAlign: TextAlign.start,
                      maxLines: 2,
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
