import 'package:flutter/material.dart';

/// Single source of colour for the app. Screens never carry inline hex —
/// see the Flutter conventions in CLAUDE.md.
///
/// A light palette on a near-white canvas with a deep teal accent. This
/// replaced the dark violet scheme, and it also ends the divergence
/// CLAUDE.md used to record: the apps and the Filament admin panel are
/// both teal now, so a brand change moves one direction rather than two.
///
/// Going light inverts every contrast assumption the violet palette was
/// built on. Text is now dark ink on white rather than near-white on
/// near-black, and the accent has to carry white text on *buttons* while
/// staying readable as *text* on white — which is why [primaryColor] is
/// the deep teal-700 and not the bright teal-500. Each token below says
/// what it was checked against.
///
/// Member names are kept identical to the demo-app (primaryColor,
/// secondaryColor, grayColor, ...) so screens ported from it compile
/// without edits. Only the values changed.
class ColorRes {
  // ── Brand ────────────────────────────────────────────────────────────────
  /// teal-700. Buttons, focus rings, active states, and accent text.
  ///
  /// White on it is 5.4:1, which passes AA for the button label; and it is
  /// 5.4:1 as text on [backgroundColor] too, so the same token works for
  /// an accent label without a second value.
  static Color primaryColor = const Color(0xFF0F766E);

  /// teal-500. The bright end of gradients, icon fills on tinted tiles,
  /// and large decorative accents.
  ///
  /// Deliberately NOT used behind white text or for body-sized text on
  /// white: it is 2.3:1 either way and fails AA badly. When something
  /// needs to read, use [primaryColor].
  static Color primaryColorLight = const Color(0xFF14B8A6);

  /// teal-900. The deep end of the header gradient and pressed states.
  static Color primaryColorDark = const Color(0xFF134E4A);

  // ── Surfaces ─────────────────────────────────────────────────────────────
  /// App background. An off-white with a cool cast rather than pure white,
  /// so white cards read as raised against it.
  static Color backgroundColor = const Color(0xFFF6F7F9);

  /// Cards, sheets, nav, input fills. White, one step *up* from the canvas
  /// — the opposite direction to the old dark theme, where surfaces were
  /// lighter than a near-black canvas.
  static Color surfaceColor = const Color(0xFFFFFFFF);

  /// Pressed surfaces and secondary button fills.
  static Color surfaceElevatedColor = const Color(0xFFF2F4F7);

  /// Hairline for borders and dividers.
  static Color borderColor = const Color(0xFFE4E7EC);

  // ── Text ─────────────────────────────────────────────────────────────────
  /// Primary text. Near-black with a blue cast, 16.8:1 on
  /// [backgroundColor].
  ///
  /// In the dark theme this role was near-white; the name is kept so
  /// ported screens still compile.
  static Color secondaryColor = const Color(0xFF101828);

  /// Secondary text, hints, disabled states. 6.4:1 on [backgroundColor],
  /// so hint text stays readable rather than decorative.
  static Color grayColor = const Color(0xFF5B6475);

  static Color whiteColor = const Color(0xFFFFFFFF);
  static Color blackColor = const Color(0xFF101828);

  // ── Status ───────────────────────────────────────────────────────────────
  // Teal sits close to green, so success is the one status colour that
  // needs care: #12B76A is far enough round the wheel to read as a
  // different signal rather than as the accent.
  static Color errorColor = const Color(0xFFD92D20); // red-600
  static Color warningColor = const Color(0xFFDC6803); // amber-600
  static Color successColor = const Color(0xFF12B76A); // green-500

  static Color transparent = Colors.transparent;

  /// Swatch for ThemeData.primarySwatch.
  static MaterialColor primaryMaterialColor =
      MaterialColor(ColorRes.primaryColor.toARGB32(), <int, Color>{
        50: ColorRes.primaryColor.withValues(alpha: 0.1),
        100: ColorRes.primaryColor.withValues(alpha: 0.2),
        200: ColorRes.primaryColor.withValues(alpha: 0.3),
        300: ColorRes.primaryColor.withValues(alpha: 0.4),
        400: ColorRes.primaryColor.withValues(alpha: 0.5),
        500: ColorRes.primaryColor.withValues(alpha: 0.6),
        600: ColorRes.primaryColor.withValues(alpha: 0.7),
        700: ColorRes.primaryColor.withValues(alpha: 0.8),
        800: ColorRes.primaryColor.withValues(alpha: 0.9),
        900: ColorRes.primaryColor,
      });
}
