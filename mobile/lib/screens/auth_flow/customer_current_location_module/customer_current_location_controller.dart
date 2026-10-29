import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../utils/location_capture.dart';
import '../../../utils/place_service.dart';

/// "Current location" (SPEC section 4.2) — take a GPS fix, name it, and
/// let the customer confirm before anything is committed.
///
/// The confirmation step is the point of the screen. GPS can land a
/// street away, so a fix that silently became the shopping location
/// would be wrong often enough to matter; showing the resolved place
/// and the coordinates lets the customer notice before searching from
/// the wrong spot.
class CustomerCurrentLocationController extends GetxController {
  bool isDetecting = false;

  /// Null until a fix resolves — the screen shows its pulse animation
  /// and then either this or [failed].
  PlaceSuggestion? place;

  /// GPS was refused, unavailable, or produced nothing usable.
  bool failed = false;

  double? latitude;
  double? longitude;

  bool get hasFix => latitude != null && longitude != null;

  @override
  void onInit() {
    super.onInit();
    detect();
  }

  Future<void> detect() async {
    isDetecting = true;
    failed = false;
    update();

    // LocationCapture already surfaces its own toast for a disabled
    // service or a denied permission, and handles the request flow —
    // the same one the salesman's Add Vendor screen uses.
    final position = await LocationCapture.detect();

    if (position == null) {
      isDetecting = false;
      failed = true;
      update();
      return;
    }

    latitude = position.latitude;
    longitude = position.longitude;

    // A failed reverse lookup is not a failed fix: the coordinates are
    // what vendor search actually needs, and the screen can show those
    // alone. Only the human-readable label is missing.
    place = await PlaceService.instance.addressAt(
      position.latitude,
      position.longitude,
    );

    isDetecting = false;
    update();
  }

  /// Hands the fix back to whoever opened this screen.
  void confirm() {
    if (!hasFix) {
      Utils.showToast(tr(StringRes.locationUnavailable), isError: true);
      return;
    }

    Get.back(
      result: place ??
          // No label resolved — still a usable location, just unnamed.
          PlaceSuggestion(
            id: 'gps:$latitude,$longitude',
            title: tr(StringRes.currentLocationTitle),
            subtitle: '',
            latitude: latitude,
            longitude: longitude,
          ),
    );
  }
}
