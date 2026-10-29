import 'dart:async';
import 'dart:io' show Platform;

import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import '../constants/pref_keys.dart';
import '../constants/string_res.dart';
import '../network/data_source.dart';
import '../widgets/base_services.dart';
import '../widgets/base_text.dart';
import 'injector.dart';

/// Exact call length on Android (customer app), through MainActivity's
/// "service_marketplace/call_tracker" channel:
///
/// * READ_PHONE_STATE — CallStateReceiver hears the call go off-hook and
///   back to idle, even if the app is closed in the meantime.
/// * "Display over other apps" (optional) — CallBubbleService floats the
///   app icon with a live timer over the dialler, and the app comes back
///   by itself the moment the call is cut.
///
/// The length waits on the phone until it has been sent ([flush]), so a
/// customer who never returns still has it reported on their next visit.
/// Without the phone permission (or on iOS) VendorDetailController falls
/// back to its approximate tap-to-return timing.
class CallTracker {
  CallTracker._();

  static const MethodChannel _channel = MethodChannel('service_marketplace/call_tracker');

  /// Swappable seam for tests — there is no Android host under flutter test.
  @visibleForTesting
  static bool Function() isSupported = () => !kIsWeb && Platform.isAndroid;

  static AppLifecycleListener? _lifecycle;
  static Timer? _poll;
  static bool _flushing = false;

  /// Once at startup in the customer app: sends any length left over from
  /// a call the customer never came back from, and checks again on every
  /// return to the app.
  static void init() {
    if (!isSupported()) {
      return;
    }

    _lifecycle ??= AppLifecycleListener(onResume: () => unawaited(_onResume()));
    unawaited(flush());
  }

  /// Before placing a call. true → exact timing is available; false → use
  /// the approximate fallback; null → don't call right now (the customer
  /// went to Settings to allow the bubble and will tap Call again).
  static Future<bool?> prepare() async {
    if (!isSupported() || !Injector.callTrackingEnabled) {
      return false;
    }

    try {
      var status = await _status();

      if (status['phone'] != true) {
        final granted = await _channel.invokeMethod<bool>('requestPhonePermission') ?? false;

        if (!granted) {
          return false;
        }

        status = await _status();
      }

      // The bubble is asked for once; "Not now" still times the call exactly.
      final asked = Injector.prefs?.getBool(PrefKeys.callBubbleAsked) ?? false;

      if (status['overlay'] != true && !asked) {
        await Injector.prefs?.setBool(PrefKeys.callBubbleAsked, true);

        if (await _askForBubble()) {
          await _channel.invokeMethod<void>('openOverlaySettings');
          return null;
        }
      }

      return true;
    } catch (e) {
      if (kDebugMode) {
        print('Call tracker prepare error $e');
      }
      return false;
    }
  }

  /// Right before the dialler opens: remembers the lead and shows the bubble.
  static Future<void> start(int leadId) async {
    try {
      await _channel.invokeMethod<bool>('start', {'leadId': leadId});
      _poll?.cancel();
      // Covers the app already being open when the call ends (bubble tap
      // mid-call), when no resume event follows.
      _poll = Timer.periodic(const Duration(seconds: 3), (_) => unawaited(flush()));
    } catch (e) {
      if (kDebugMode) {
        print('Call tracker start error $e');
      }
    }
  }

  /// Stops the 3-second check started by [start].
  @visibleForTesting
  static void stopPolling() {
    _poll?.cancel();
    _poll = null;
  }

  /// Sends a finished call's length. Any server answer is final (recorded,
  /// too late, not a call); only no answer at all is kept for next time.
  static Future<void> flush() async {
    if (!isSupported() || _flushing) {
      return;
    }

    _flushing = true;

    try {
      final state = await _channel.invokeMapMethod<String, dynamic>('poll');
      final leadId = state?['leadId'] as int?;
      final seconds = state?['seconds'] as int?;

      if (leadId != null && seconds != null) {
        final response = await DataSource.instance.recordLeadDurationAPI(
          leadId: leadId,
          durationSeconds: seconds,
        );

        if (response != null) {
          await _channel.invokeMethod<void>('clearFinished');
        }
      }

      if (state?['pending'] != true) {
        _poll?.cancel();
        _poll = null;
      }
    } catch (e) {
      if (kDebugMode) {
        print('Call tracker flush error $e');
      }
    } finally {
      _flushing = false;
    }
  }

  static Future<void> _onResume() async {
    try {
      // Back without having dialled: drop the timing and the bubble.
      await _channel.invokeMethod<void>('appResumed');
    } catch (_) {}

    await flush();
  }

  static Future<Map<String, dynamic>> _status() async =>
      await _channel.invokeMapMethod<String, dynamic>('status') ?? const {};

  static Future<bool> _askForBubble() async {
    final enable = await Get.dialog<bool>(
      AlertDialog(
        backgroundColor: ServiceTokens.card,
        title: BaseTextDMSans(
          text: StringRes.callBubbleTitle,
          fontSize: 16,
          fontWeight: FontWeight.w600,
          color: ServiceTokens.text,
          textAlign: TextAlign.start,
        ).tr(),
        content: BaseTextDMSans(
          text: StringRes.callBubbleDesc,
          fontSize: 12,
          color: ServiceTokens.muted,
          textAlign: TextAlign.start,
        ).tr(),
        actions: [
          TextButton(
            onPressed: () => Get.back(result: false),
            child: BaseTextDMSans(
              text: StringRes.callBubbleNotNow,
              fontSize: 12,
              color: ServiceTokens.muted,
            ).tr(),
          ),
          TextButton(
            onPressed: () => Get.back(result: true),
            child: BaseTextDMSans(
              text: StringRes.callBubbleEnable,
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: ServiceTokens.accent,
            ).tr(),
          ),
        ],
      ),
    );

    return enable ?? false;
  }
}
