import 'dart:async';

import 'package:easy_localization/easy_localization.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../../constants/app.export.dart';
import '../../../utils/location_capture.dart';
import '../../../utils/place_service.dart';

/// "Choose on map" (SPEC section 4.2) — a fixed centre pin over a map
/// the customer moves beneath it.
///
/// The pin does not move; the map does. A draggable marker means two
/// things can move and the one under your thumb is the one you cannot
/// see, which is why every maps app settled on this instead.
class CustomerMapLocationController extends GetxController {
  /// Ahmedabad, where the seeded zones are. Only ever the opening frame
  /// before GPS or a passed-in point replaces it — a map that opens on
  /// null island is worse than one that opens somewhere plausible.
  static const LatLng _fallbackCentre = LatLng(23.0225, 72.5714);

  CustomerMapLocationController({this.initialPoint});

  /// Where to open, when the caller already has a location.
  final LatLng? initialPoint;

  GoogleMapController? mapController;

  late LatLng centre = initialPoint ?? _fallbackCentre;

  PlaceSuggestion? place;

  /// True while the address under the pin is being looked up, so the
  /// card can say so instead of showing the previous place as if it
  /// were still correct.
  bool isResolving = false;

  /// Debounces the reverse lookup. The map fires a stream of camera
  /// positions during a drag; geocoding each one would be both slow and
  /// pointless, since only where the customer stops matters.
  Timer? _debounce;

  /// Only the newest lookup may publish — a slow earlier response must
  /// not overwrite a newer one.
  int _requestId = 0;

  bool get hasPlacesKey => PlaceService.instance.hasPlacesKey;

  @override
  void onInit() {
    super.onInit();

    if (initialPoint == null) {
      _openOnCurrentPosition();
    } else {
      _resolveCentre();
    }
  }

  /// Opens on the customer's own position when the caller had none.
  ///
  /// Deliberately silent on failure: this screen's job is picking a spot
  /// by hand, so a refused GPS permission just means the map opens on
  /// the fallback rather than interrupting with an error.
  Future<void> _openOnCurrentPosition() async {
    final position = await LocationCapture.detect();

    if (position != null) {
      centre = LatLng(position.latitude, position.longitude);
      await mapController?.animateCamera(CameraUpdate.newLatLng(centre));
      update();
    }

    _resolveCentre();
  }

  void onMapCreated(GoogleMapController controller) {
    mapController = controller;
  }

  /// Fires continuously while the map moves — tracked, not resolved.
  void onCameraMove(CameraPosition position) {
    centre = position.target;
  }

  /// Fires once the map settles. This is the only point worth geocoding.
  void onCameraIdle() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 280), _resolveCentre);
  }

  Future<void> _resolveCentre() async {
    final id = ++_requestId;

    isResolving = true;
    update();

    final resolved = await PlaceService.instance.addressAt(
      centre.latitude,
      centre.longitude,
    );

    if (id != _requestId) {
      return;
    }

    place = resolved;
    isResolving = false;
    update();
  }

  /// Recentres on the customer's own position.
  Future<void> goToMyLocation() async {
    final position = await LocationCapture.detect();

    if (position == null) {
      return;
    }

    centre = LatLng(position.latitude, position.longitude);
    await mapController?.animateCamera(CameraUpdate.newLatLngZoom(centre, 16));
    update();

    // The camera-idle callback covers the usual case, but an animation
    // that ends exactly where it started fires no idle event.
    _resolveCentre();
  }

  /// Hands the pinned point back to whoever opened this screen.
  void confirm() {
    Get.back(
      result: place?.withPoint(centre.latitude, centre.longitude) ??
          // Nothing reverse-geocoded, but the point itself is what
          // vendor search needs — only the label is missing.
          PlaceSuggestion(
            id: 'map:${centre.latitude},${centre.longitude}',
            title: tr(StringRes.selectedLocationLabel),
            subtitle: '',
            latitude: centre.latitude,
            longitude: centre.longitude,
          ),
    );
  }

  @override
  void onClose() {
    _debounce?.cancel();
    mapController?.dispose();
    super.onClose();
  }
}
