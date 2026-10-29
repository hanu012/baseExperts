import 'package:flutter/material.dart';

import '../utils/utils.dart';

/// Non-UI constants and the sizing extensions every screen depends on.
///
/// This file was not in the original port list but the reference screens use
/// `.getSize` and `.heightSpacer` on nearly every line, so the foundation does
/// not compile without it.
class Constants {
  static const String english = 'en';
  static const String hindi = 'hi';
  static const String gujarati = 'gu';

  /// Every bundled language, each named in its own script — someone who
  /// only reads Gujarati has to be able to find Gujarati in the list, so
  /// these are deliberately not run through translation.
  static const Map<String, String> languages = {
    english: 'English',
    hindi: 'हिन्दी',
    gujarati: 'ગુજરાતી',
  };

  // Roles, mirroring the UserRole enum on the backend (SPEC section 1).
  static const String admin = 'admin';
  static const String salesman = 'salesman';
  static const String vendor = 'vendor';
  static const String customer = 'customer';

  /// Whether the salesman app shows anything about money it has earned:
  /// the Earnings stat tile and tab on the home screen, the Earnings stat
  /// on the profile, the Performance group (Earnings & Payouts, Targets),
  /// and the commission rate on Personal details.
  ///
  /// Switched off for now at the client's request. Deliberately ONE flag
  /// rather than five edits, because these are a single decision and have
  /// to come back together — flipping this to true is the whole revert.
  /// The screens, models and endpoints behind them are all left intact.
  static const bool showSalesmanEarnings = false;

  /// Whether the vendor app offers an upgrade anywhere: the "Need more
  /// room?" card on Overview and the "Upgrade to Platinum" row on the
  /// profile.
  ///
  /// Switched off for now at the client's request. One flag rather than
  /// two edits because they are a single decision — an app that pitches
  /// an upgrade in one place and not the other reads as a bug. The
  /// upsell widgets and the upgradePlan lookup behind them are left
  /// intact, so flipping this to true is the whole revert.
  static const bool showVendorUpsell = false;

  /// Customer location picking, modelled on the patient app: "Set your
  /// location" (search + current location + saved addresses), a map
  /// confirm-and-save form, and Profile → My addresses.
  ///
  /// false restores the previous flow exactly — CustomerSelectLocationView
  /// with its GPS and map hand-off screens, and the profile's single
  /// "Saved location" row. Those screens are untouched and still compiled,
  /// so flipping this is the whole revert. The salesman's add-vendor
  /// location picker always uses the previous screen either way.
  static const bool useNewLocationFlow = true;
}

/// Font families. Poppins, bundled in pubspec.yaml — the same family as the
/// provider app. (BaseTextDMSans keeps its demo-app name; it renders Poppins.)
class FontFamily {
  static const String poppins = 'Poppins';
}

extension IntExtension on int? {
  Widget get heightSpacer => SizedBox(height: Utils.getSize((this ?? 0).toDouble()));

  Widget get widthSpacer => SizedBox(width: Utils.getSize((this ?? 0).toDouble()));

  double get getSize => Utils.getSize((this ?? 0).toDouble());

  double get getFontSize => Utils.getFontSize((this ?? 0).toDouble());
}

extension DoubleExtension on double? {
  double get getFontSize => Utils.getFontSize((this ?? 0).toDouble());

  double get getSize => Utils.getSize((this ?? 0).toDouble());
}
