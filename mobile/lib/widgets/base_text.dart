import 'package:flutter/material.dart';

import '../constants/color_res.dart';
import '../constants/constant.dart';

/// Both classes extend [Text] rather than composing it. That is deliberate —
/// it is the demo-app's pattern, and it is what lets easy_localization's
/// `.tr()` extension chain onto them: `BaseTextDMSans(text: ...).tr()`.
///
/// Two departures from the demo-app source, neither of which changes the
/// constructor API, so ported screens are unaffected:
///
///  * `textScaleFactor: 0.80` has been deprecated since Flutter 3.12; the
///    modern `textScaler` equivalent is used.
///  * Parameters that [Text] already declares (textAlign, overflow, maxLines,
///    textDirection, softWrap) are passed straight to `super` rather than
///    being redeclared as fields. The demo-app shadowed them, which the
///    analyzer flags and nothing ever read.
class BaseText extends Text {
  final String text;
  final Color? color;
  final double? fontSize;
  final FontWeight? fontWeight;
  final double? letterSpacing;
  final String? fontFamily;
  final TextDecoration? textDecoration;
  final double? lineHeight;

  BaseText({
    super.key,
    required this.text,
    this.color,
    this.fontSize,
    this.fontWeight,
    this.fontFamily,
    this.letterSpacing,
    this.textDecoration,
    this.lineHeight,
    TextAlign? textAlign,
    TextOverflow? overflow,
    int? maxLines,
    TextDirection? textDirection,
    bool softWrap = true,
  }) : super(
          text,
          textScaler: const TextScaler.linear(0.80),
          textAlign: textAlign ?? TextAlign.center,
          overflow: overflow ?? TextOverflow.ellipsis,
          maxLines: maxLines,
          softWrap: softWrap,
          textDirection: textDirection,
          style: TextStyle(
            decoration: textDecoration ?? TextDecoration.none,
            color: color ?? ColorRes.secondaryColor,
            fontSize: fontSize != null ? (fontSize + 4).getFontSize : (20).getFontSize,
            fontWeight: fontWeight ?? FontWeight.w400,
            fontFamily: fontFamily ?? FontFamily.poppins,
            height: lineHeight,
            letterSpacing: letterSpacing ?? 0.1,
          ),
        );
}

class BaseTextDMSans extends Text {
  final String text;
  final Color? color;
  final Color? decorationColor;
  final double? fontSize;
  final FontWeight? fontWeight;
  final double? letterSpacing;
  final String? fontFamily;
  final TextDecoration? textDecoration;
  final double? lineHeight;

  BaseTextDMSans({
    super.key,
    required this.text,
    this.color,
    this.fontSize,
    this.fontWeight,
    this.decorationColor,
    this.fontFamily,
    this.letterSpacing,
    this.textDecoration,
    this.lineHeight,
    TextAlign? textAlign,
    TextOverflow? overflow,
    int? maxLines,
    TextDirection? textDirection,
    bool softWrap = true,
  }) : super(
          text,
          textScaler: const TextScaler.linear(0.80),
          textAlign: textAlign ?? TextAlign.center,
          overflow: overflow ?? TextOverflow.ellipsis,
          maxLines: maxLines,
          softWrap: softWrap,
          textDirection: textDirection,
          style: TextStyle(
            decoration: textDecoration ?? TextDecoration.none,
            // Defaults to near-white: this is a dark theme, where the
            // demo-app's near-black body colour would be invisible.
            color: color ?? ColorRes.secondaryColor,
            fontSize: fontSize != null ? (fontSize + 4).getFontSize : (20).getFontSize,
            fontWeight: fontWeight ?? FontWeight.w400,
            fontFamily: fontFamily ?? FontFamily.poppins,
            height: lineHeight,
            decorationColor: decorationColor,
            letterSpacing: letterSpacing ?? 0.1,
          ),
        );
}
