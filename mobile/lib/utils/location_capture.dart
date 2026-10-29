import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

import '../constants/string_res.dart';
import 'utils.dart';

/// Shared GPS capture flow (SPEC section 2.2, task 3.2) — service check,
/// permission check/request, then a high-accuracy fix.
///
/// Extracted from `add_vendor_controller.dart`'s original inline
/// `captureLocation()` (task 3.2, salesman's Add Vendor GPS capture) so
/// the customer home screen (task 4.6) reuses the exact same flow instead
/// of a second copy — both call sites now call [detect] and branch on
/// whether it returns a position.
class LocationCapture {
  static Future<Position?> detect() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        Utils.showToast(tr(StringRes.locationServiceDisabled), isError: true);
        return null;
      }

      LocationPermission permission = await Geolocator.checkPermission();

      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        Utils.showToast(tr(StringRes.locationPermissionDenied), isError: true);
        return null;
      }

      return await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high),
      );
    } catch (e) {
      if (kDebugMode) {
        print('Location capture error $e');
      }
      Utils.showToast(tr(StringRes.locationCaptureFailed), isError: true);
      return null;
    }
  }
}
