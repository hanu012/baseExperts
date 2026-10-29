import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator_platform_interface/geolocator_platform_interface.dart';
import 'package:service_marketplace/utils/location_capture.dart';

/// Same fake-platform pattern add_vendor_controller_test.dart already
/// established — geolocator's static methods delegate to whatever is set
/// as [GeolocatorPlatform.instance], so no device/platform channel is
/// needed to control the outcome.
class _FakeGeolocator extends GeolocatorPlatform {
  bool serviceEnabled = true;
  LocationPermission permission = LocationPermission.always;
  LocationPermission requestResult = LocationPermission.always;
  bool throwsOnPosition = false;

  @override
  Future<bool> isLocationServiceEnabled() async => serviceEnabled;

  @override
  Future<LocationPermission> checkPermission() async => permission;

  @override
  Future<LocationPermission> requestPermission() async => requestResult;

  @override
  Future<Position> getCurrentPosition({LocationSettings? locationSettings}) async {
    if (throwsOnPosition) {
      throw Exception('gps hardware error');
    }

    return Position(
      latitude: 23.03,
      longitude: 72.55,
      timestamp: DateTime(2026, 1, 1),
      accuracy: 5,
      altitude: 0,
      altitudeAccuracy: 0,
      heading: 0,
      headingAccuracy: 0,
      speed: 0,
      speedAccuracy: 0,
    );
  }
}

void main() {
  testWidgets('returns a position when the service is enabled and permission is granted', (
    tester,
  ) async {
    GeolocatorPlatform.instance = _FakeGeolocator();

    final position = await LocationCapture.detect();

    expect(position?.latitude, 23.03);
    expect(position?.longitude, 72.55);
  });

  testWidgets('returns null when the location service is disabled', (tester) async {
    GeolocatorPlatform.instance = _FakeGeolocator()..serviceEnabled = false;

    expect(await LocationCapture.detect(), isNull);
  });

  testWidgets('requests permission when initially denied, then proceeds if granted', (
    tester,
  ) async {
    GeolocatorPlatform.instance = _FakeGeolocator()
      ..permission = LocationPermission.denied
      ..requestResult = LocationPermission.whileInUse;

    final position = await LocationCapture.detect();

    expect(position, isNotNull);
  });

  testWidgets('returns null when permission is denied even after requesting', (tester) async {
    GeolocatorPlatform.instance = _FakeGeolocator()
      ..permission = LocationPermission.denied
      ..requestResult = LocationPermission.denied;

    expect(await LocationCapture.detect(), isNull);
  });

  testWidgets('returns null when permission is permanently denied', (tester) async {
    GeolocatorPlatform.instance = _FakeGeolocator()..permission = LocationPermission.deniedForever;

    expect(await LocationCapture.detect(), isNull);
  });

  testWidgets('returns null and does not crash when getCurrentPosition throws', (tester) async {
    GeolocatorPlatform.instance = _FakeGeolocator()..throwsOnPosition = true;

    expect(await LocationCapture.detect(), isNull);
  });
}
