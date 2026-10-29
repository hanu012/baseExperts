import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator_platform_interface/geolocator_platform_interface.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/customer_home_module/customer_home_controller.dart';

/// Same fake-platform pattern as add_vendor_controller_test.dart and
/// location_capture_test.dart.
class _FakeGeolocator extends GeolocatorPlatform {
  bool serviceEnabled = true;
  LocationPermission permission = LocationPermission.always;

  @override
  Future<bool> isLocationServiceEnabled() async => serviceEnabled;

  @override
  Future<LocationPermission> checkPermission() async => permission;

  @override
  Future<LocationPermission> requestPermission() async => permission;

  @override
  Future<Position> getCurrentPosition({LocationSettings? locationSettings}) async {
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

/// Answers POST /api/customers/me/location the way task 4.6's real
/// endpoint does.
class _RecordingLocationDataSource extends DataSource {
  Map<String, dynamic>? capturedBody;
  bool zoneMatches = true;
  bool fails = false;
  bool categoriesFail = false;

  @override
  Future<CommonResponse?> updateCustomerLocationAPI({
    double? latitude,
    double? longitude,
    String? pincode,
  }) async {
    capturedBody = {
      'latitude': ?latitude,
      'longitude': ?longitude,
      'pincode': ?pincode,
    };

    if (fails) {
      return null;
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'customer': {'latitude': latitude, 'longitude': longitude, 'pincode': pincode},
        'zone': zoneMatches ? {'id': 7, 'name': 'Gota'} : null,
      },
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> categoriesAPI() async {
    if (categoriesFail) {
      return null;
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': [
        {
          'id': 1,
          'name': 'AC Repair',
          'slug': 'ac-repair',
          'icon_url': null,
          'sort_order': 1,
          'subcategories': [
            {
              'id': 10,
              'category_id': 1,
              'name': 'Gas Filling',
              'slug': 'gas-filling',
              'icon_url': null,
              'sort_order': 1,
            },
          ],
        },
      ],
      'error': null,
    });
  }
}

void main() {
  testWidgets('GPS success with a zone match resolves the header', (tester) async {
    GeolocatorPlatform.instance = _FakeGeolocator();
    final fake = _RecordingLocationDataSource();
    DataSource.instance = fake;

    final controller = CustomerHomeController();
    await controller.detectLocation();

    expect(controller.showPincodeFallback, isFalse);
    expect(controller.zone?.name, 'Gota');
    expect(fake.capturedBody?['latitude'], 23.03);
    expect(fake.capturedBody?['longitude'], 72.55);

    controller.onClose();
  });

  testWidgets('GPS success with no zone match shows the pincode fallback', (tester) async {
    GeolocatorPlatform.instance = _FakeGeolocator();
    final fake = _RecordingLocationDataSource()..zoneMatches = false;
    DataSource.instance = fake;

    final controller = CustomerHomeController();
    await controller.detectLocation();

    expect(controller.showPincodeFallback, isTrue);
    expect(controller.zone, isNull);

    controller.onClose();
  });

  testWidgets('GPS denial shows the fallback state with no network call', (tester) async {
    GeolocatorPlatform.instance = _FakeGeolocator()..permission = LocationPermission.denied;
    final fake = _RecordingLocationDataSource();
    DataSource.instance = fake;

    final controller = CustomerHomeController();
    await controller.detectLocation();

    expect(controller.showPincodeFallback, isTrue);
    expect(fake.capturedBody, isNull);

    controller.onClose();
  });

  testWidgets('submitPincodeAPI resolves the header when the pincode matches', (tester) async {
    final fake = _RecordingLocationDataSource();
    DataSource.instance = fake;

    final controller = CustomerHomeController();
    controller.pincodeController.text = '380001';

    await controller.submitPincodeAPI();

    expect(controller.showPincodeFallback, isFalse);
    expect(controller.zone?.name, 'Gota');
    expect(fake.capturedBody?['pincode'], '380001');

    controller.onClose();
  });

  testWidgets('submitPincodeAPI with an empty pincode makes no network call', (tester) async {
    final fake = _RecordingLocationDataSource();
    DataSource.instance = fake;

    final controller = CustomerHomeController();
    controller.pincodeController.text = '   ';

    await controller.submitPincodeAPI();

    expect(fake.capturedBody, isNull);

    controller.onClose();
  });

  testWidgets('a pincode matching no zone keeps the fallback state', (tester) async {
    final fake = _RecordingLocationDataSource()..zoneMatches = false;
    DataSource.instance = fake;

    final controller = CustomerHomeController();
    controller.pincodeController.text = '999999';

    await controller.submitPincodeAPI();

    expect(controller.showPincodeFallback, isTrue);
    expect(controller.zone, isNull);

    controller.onClose();
  });

  testWidgets('change location (detectLocation again) re-runs GPS detection', (tester) async {
    GeolocatorPlatform.instance = _FakeGeolocator()..permission = LocationPermission.denied;
    final fake = _RecordingLocationDataSource();
    DataSource.instance = fake;

    final controller = CustomerHomeController();
    await controller.detectLocation();
    expect(controller.showPincodeFallback, isTrue);

    // The user granted permission after the first denial.
    (GeolocatorPlatform.instance as _FakeGeolocator).permission = LocationPermission.always;

    await controller.detectLocation();

    expect(controller.showPincodeFallback, isFalse);
    expect(controller.zone?.name, 'Gota');

    controller.onClose();
  });

  testWidgets('fetchCategoriesAPI populates categories from a faked response', (tester) async {
    final fake = _RecordingLocationDataSource();
    DataSource.instance = fake;

    final controller = CustomerHomeController();
    await controller.fetchCategoriesAPI();

    expect(controller.isLoadingCategories, isFalse);
    expect(controller.categories, hasLength(1));
    expect(controller.categories.first.name, 'AC Repair');
    expect(controller.categories.first.subcategories, hasLength(1));
    expect(controller.categories.first.subcategories.first.name, 'Gas Filling');

    controller.onClose();
  });

  testWidgets('a failed category fetch leaves categories empty without crashing', (tester) async {
    final fake = _RecordingLocationDataSource()..categoriesFail = true;
    DataSource.instance = fake;

    final controller = CustomerHomeController();
    await controller.fetchCategoriesAPI();

    expect(controller.isLoadingCategories, isFalse);
    expect(controller.categories, isEmpty);

    controller.onClose();
  });
}
