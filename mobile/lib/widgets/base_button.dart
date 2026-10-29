import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/material.dart';

import '../constants/color_res.dart';
import '../constants/constant.dart';
import '../utils/utils.dart';
import 'base_text.dart';

/// Extends [ElevatedButton], matching the demo-app pattern.
///
/// `MaterialStateProperty` is now a deprecated typedef for
/// `WidgetStateProperty`; the modern name is used here. The constructor API is
/// unchanged.
class BaseRaisedButton extends ElevatedButton {
  BaseRaisedButton({
    super.key,
    String? buttonText,
    super.onPressed,
    double? fontSize,
    FontWeight? fontWeight,
    Color? buttonColor,
    double? buttonVerticalPadding,
    double? buttonHorizontalPadding,
    double? borderRadius,
    Color? textColor,
    Color? borderColor,
    Widget? icon,
    bool isExpanded = true,
    Widget? trailingIcon,
    double? borderWidth,
    MaterialTapTargetSize? tapTargetSize,
    WidgetStateProperty<OutlinedBorder?>? shape,
    int? maxLines,
    TextOverflow? overflow,
    String? fontFamily,
  }) : super(
          style: ButtonStyle(
            tapTargetSize: tapTargetSize,
            shape: shape ??
                WidgetStateProperty.all<RoundedRectangleBorder>(
                  RoundedRectangleBorder(
                    // Radius, padding and label size match the provider
                    // app's BaseRaisedButton.
                    borderRadius: BorderRadius.circular(borderRadius ?? 12.getSize),
                    side: BorderSide(
                      color: borderColor ?? Colors.transparent,
                      width: borderWidth ?? 1.getSize,
                    ),
                  ),
                ),
            backgroundColor: WidgetStateProperty.all(buttonColor ?? ColorRes.primaryColor),
            elevation: WidgetStateProperty.all(0),
            padding: WidgetStateProperty.all<EdgeInsetsGeometry>(
              EdgeInsets.symmetric(
                vertical: buttonVerticalPadding ?? Utils.getSize(14),
                horizontal: buttonHorizontalPadding ?? Utils.getSize(16),
              ),
            ),
          ),
          child: _Label(
            buttonText: buttonText,
            textColor: textColor,
            fontSize: fontSize,
            fontWeight: fontWeight,
            fontFamily: fontFamily,
            icon: icon,
            trailingIcon: trailingIcon,
            isExpanded: isExpanded,
            maxLines: maxLines,
            overflow: overflow,
          ),
        );
}

/// Pulled out of the constructor so the row can be assembled with statements
/// rather than nested conditionals inside a `super(...)` argument.
class _Label extends StatelessWidget {
  const _Label({
    this.buttonText,
    this.textColor,
    this.fontSize,
    this.fontWeight,
    this.fontFamily,
    this.icon,
    this.trailingIcon,
    this.isExpanded = true,
    this.maxLines,
    this.overflow,
  });

  final String? buttonText;
  final Color? textColor;
  final double? fontSize;
  final FontWeight? fontWeight;
  final String? fontFamily;
  final Widget? icon;
  final Widget? trailingIcon;
  final bool isExpanded;
  final int? maxLines;
  final TextOverflow? overflow;

  @override
  Widget build(BuildContext context) {
    final hasText = buttonText != null && buttonText!.isNotEmpty;

    final label = BaseText(
      text: buttonText ?? '',
      // White on the violet accent: 4.2:1 on violet-500 and 7.0:1 on
      // violet-700, versus 4.4:1 for dark ink — equivalent contrast, and white
      // is what the design language calls for. (Under the old teal-400 accent
      // this defaulted to dark ink, where white genuinely failed.)
      color: textColor ?? ColorRes.whiteColor,
      fontSize: fontSize ?? Utils.getFontSize(12),
      fontWeight: fontWeight ?? FontWeight.w500,
      fontFamily: fontFamily ?? FontFamily.poppins,
      textAlign: TextAlign.center,
      maxLines: maxLines,
      overflow: overflow,
    ).tr();

    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      mainAxisSize: MainAxisSize.min,
      children: [
        ?icon,
        if (icon != null && hasText) 8.widthSpacer,
        if (hasText) isExpanded ? Flexible(child: label) : label,
        if (trailingIcon != null && hasText) 8.widthSpacer,
        ?trailingIcon,
      ],
    );
  }
}
