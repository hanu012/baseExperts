import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../common_model/user_model.dart';
import '../constants/constant.dart';
import '../constants/pref_keys.dart';
import 'socket_service.dart';

/// Static holder for anything that must survive across screens — access token,
/// user profile, preferences.
///
/// Per CLAUDE.md, this is the only shared-state layer: screens do not stand up
/// their own stores, and nothing else touches SharedPreferences directly.
class Injector {
  static SharedPreferences? prefs;

  static UserModel? userData;
  static String accessToken = '';
  static String language = Constants.english;
  static bool isGuestUser = true;
  static bool skipTap = false;
  static bool enableNotification = true;

  /// Admin Settings → "Call tracking in apps": vendor Recent calls,
  /// customer My calls, and timing each call. On until the server says
  /// otherwise; refreshed at every launch (Utils.refreshAppSettings).
  static bool callTrackingEnabled = true;

  /// Where the customer is shopping from — see [setCustomerLocation].
  static String? customerLocationLabel;
  static String? customerLocationAddress;
  static double? customerLatitude;
  static double? customerLongitude;

  /// Call once from main() before runApp.
  static Future<void> initialize() async {
    prefs = await SharedPreferences.getInstance();

    accessToken = prefs?.getString(PrefKeys.accessToken) ?? '';
    language = prefs?.getString(PrefKeys.language) ?? Constants.english;
    isGuestUser = prefs?.getBool(PrefKeys.isGuestUser) ?? true;
    skipTap = prefs?.getBool(PrefKeys.skipTap) ?? false;
    enableNotification = prefs?.getBool(PrefKeys.enableNotification) ?? true;
    callTrackingEnabled = prefs?.getBool(PrefKeys.callTrackingEnabled) ?? true;

    customerLocationLabel = prefs?.getString(PrefKeys.customerLocationLabel);
    customerLocationAddress = prefs?.getString(PrefKeys.customerLocationAddress);
    customerLatitude = prefs?.getDouble(PrefKeys.customerLatitude);
    customerLongitude = prefs?.getDouble(PrefKeys.customerLongitude);

    final stored = prefs?.getString(PrefKeys.userData);
    if (stored != null && stored.isNotEmpty) {
      try {
        userData = UserModel.fromJson(jsonDecode(stored) as Map<String, dynamic>);
      } catch (_) {
        // A payload written by an older build is not worth crashing over.
        await prefs?.remove(PrefKeys.userData);
      }
    }
  }

  /// Persists the user.
  ///
  /// [isFromEditProfile] leaves the stored token alone, because a profile
  /// update response carries no token and would otherwise blank it.
  static Future<void> setUserData(
    UserModel userModel, {
    bool isFromEditProfile = false,
  }) async {
    userData = userModel;

    if (!isFromEditProfile) {
      final token = userModel.authentication?.accessToken;
      if (token != null && token.isNotEmpty) {
        await setAccessToken(token);
      }
    }

    isGuestUser = false;
    await prefs?.setBool(PrefKeys.isGuestUser, false);
    await prefs?.setString(PrefKeys.userData, jsonEncode(userModel.toJson()));
  }

  static Future<void> setAccessToken(String token) async {
    accessToken = token;
    await prefs?.setString(PrefKeys.accessToken, token);
  }

  static Future<void> setLanguage(String value) async {
    language = value;
    await prefs?.setString(PrefKeys.language, value);
  }

  /// The place the customer is shopping from (SPEC section 4.2).
  ///
  /// Persisted so the home header reads correctly on the next cold start
  /// instead of blanking while GPS re-resolves — and so a customer who
  /// deliberately chose somewhere other than where they are standing is
  /// not silently moved back by the next GPS fix.
  static Future<void> setCustomerLocation({
    required String label,
    required String address,
    required double latitude,
    required double longitude,
  }) async {
    customerLocationLabel = label;
    customerLocationAddress = address;
    customerLatitude = latitude;
    customerLongitude = longitude;

    await prefs?.setString(PrefKeys.customerLocationLabel, label);
    await prefs?.setString(PrefKeys.customerLocationAddress, address);
    await prefs?.setDouble(PrefKeys.customerLatitude, latitude);
    await prefs?.setDouble(PrefKeys.customerLongitude, longitude);
  }

  static bool get hasCustomerLocation =>
      customerLatitude != null && customerLongitude != null;

  /// Wipes everything tied to the signed-in user. Called on sign-out and on a
  /// 401 from the API.
  static Future<void> clearUserData() async {
    // Before the token goes: the socket is subscribed as this user.
    SocketService.instance.disconnect();

    userData = null;
    accessToken = '';
    isGuestUser = true;

    // The chosen location goes too: it is personal data tied to the
    // account that just signed out, and leaving it would show the next
    // person to sign in on this device where the last one was.
    customerLocationLabel = null;
    customerLocationAddress = null;
    customerLatitude = null;
    customerLongitude = null;

    await prefs?.remove(PrefKeys.accessToken);
    await prefs?.remove(PrefKeys.userData);
    await prefs?.remove(PrefKeys.customerLocationLabel);
    await prefs?.remove(PrefKeys.customerLocationAddress);
    await prefs?.remove(PrefKeys.customerLatitude);
    await prefs?.remove(PrefKeys.customerLongitude);
    await prefs?.setBool(PrefKeys.isGuestUser, true);
  }

  static bool get isLoggedIn => accessToken.isNotEmpty;

  /// Stable per-install device label sent with every login.
  ///
  /// CLAUDE.md: one personal access token per device. The server keys on this
  /// name and deletes that device's previous token when it issues a new one,
  /// so the value has to survive across sessions — a fresh name each sign-in
  /// would leave an orphaned live token behind every time.
  static Future<String> deviceName() async {
    final existing = prefs?.getString(PrefKeys.deviceName);

    if (existing != null && existing.isNotEmpty) {
      return existing;
    }

    final generated =
        '${defaultTargetPlatform.name}-${DateTime.now().millisecondsSinceEpoch}';

    await prefs?.setString(PrefKeys.deviceName, generated);

    return generated;
  }
}
