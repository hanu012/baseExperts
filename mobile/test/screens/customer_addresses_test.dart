import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/common_model/customer_address_model.dart';
import 'package:service_marketplace/constants/flavor_config.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/customer_address_form_module/customer_address_form_controller.dart';
import 'package:service_marketplace/screens/auth_flow/customer_saved_addresses_module/customer_saved_addresses_controller.dart';
import 'package:service_marketplace/screens/auth_flow/customer_saved_addresses_module/customer_saved_addresses_view.dart';
import 'package:service_marketplace/screens/auth_flow/customer_set_location_module/customer_set_location_controller.dart';
import 'package:service_marketplace/screens/auth_flow/customer_set_location_module/customer_set_location_view.dart';
import 'package:service_marketplace/utils/injector.dart';
import 'package:service_marketplace/utils/place_service.dart';

Map<String, dynamic> _addressJson({
  int id = 7,
  String label = 'home',
  bool isDefault = false,
}) =>
    {
      'id': id,
      'label': label,
      'other_label': null,
      'display_label': label == 'home' ? 'Home' : 'Office',
      'apartment_number': 'B-402',
      'building_name': 'Shivalik Heights',
      'street_address': 'Satellite Rd, Jodhpur, Ahmedabad',
      'area': 'Jodhpur',
      'city': 'Ahmedabad',
      'state': 'Gujarat',
      'pincode': '380015',
      'latitude': 23.0225,
      'longitude': 72.5714,
      'is_default': isDefault,
    };

class _AddressDataSource extends DataSource {
  _AddressDataSource(this.addresses);

  final List<Map<String, dynamic>> addresses;
  final List<int> defaultedIds = [];
  final List<double?> reportedLatitudes = [];

  @override
  Future<CommonResponse?> customerAddressesAPI() async =>
      CommonResponse.fromJson({'success': true, 'data': addresses, 'error': null});

  @override
  Future<CommonResponse?> setDefaultCustomerAddressAPI({required int addressId}) async {
    defaultedIds.add(addressId);
    return CommonResponse.fromJson({'success': true, 'data': _addressJson(id: addressId), 'error': null});
  }

  @override
  Future<CommonResponse?> updateCustomerLocationAPI({
    double? latitude,
    double? longitude,
    String? pincode,
  }) async {
    reportedLatitudes.add(latitude);
    return CommonResponse.fromJson({'success': true, 'data': {'zone': null}, 'error': null});
  }
}

void main() {
  setUpAll(() {
    FlavorConfig.initialize(FlavorConfig.customer);
  });

  tearDown(Get.reset);

  test('the address model parses the API shape and builds one address line', () {
    final address = CustomerAddressModel.fromJson(_addressJson(isDefault: true));

    expect(address.id, 7);
    expect(address.displayLabel, 'Home');
    expect(address.isDefault, isTrue);
    expect(address.hasPoint, isTrue);
    expect(address.fullAddress, 'B-402, Shivalik Heights, Satellite Rd, Jodhpur, Ahmedabad');
  });

  test('an "other" address needs a name of letters, two words at most', () {
    final controller = CustomerAddressFormController(latitude: 23, longitude: 72);

    // Not asked for unless the label is "other".
    expect(controller.validateOtherName(''), isNull);

    controller.label = 'other';
    expect(controller.validateOtherName('G'), isNotNull);
    expect(controller.validateOtherName('my parents house'), isNotNull);
    expect(controller.validateOtherName('Gym 2'), isNotNull);
    expect(controller.validateOtherName('Parents House'), isNull);
  });

  testWidgets('tapping a saved address marks it default and returns its point', (tester) async {
    final dataSource = _AddressDataSource([_addressJson(id: 11, isDefault: true)]);
    DataSource.instance = dataSource;

    PlaceSuggestion? result;

    await tester.pumpWidget(const GetMaterialApp(home: Scaffold(body: Text('home'))));
    Get.to<PlaceSuggestion?>(() => const CustomerSetLocationView())!.then((value) => result = value);
    await tester.pumpAndSettle();

    final controller = Get.find<CustomerSetLocationController>();
    expect(controller.addresses, hasLength(1));

    await controller.selectSavedAddressAPI(controller.addresses.first);
    await tester.pumpAndSettle();

    expect(dataSource.defaultedIds, [11]);
    expect(result?.latitude, 23.0225);
    expect(result?.longitude, 72.5714);
    expect(result?.title, 'Home');
    expect(find.text('home'), findsOneWidget);
  });

  testWidgets('choosing an address in My addresses makes it the current location', (tester) async {
    final dataSource = _AddressDataSource([
      _addressJson(id: 1, isDefault: true),
      _addressJson(id: 2, label: 'office'),
    ]);
    DataSource.instance = dataSource;

    await tester.pumpWidget(const GetMaterialApp(home: CustomerSavedAddressesView()));
    await tester.pumpAndSettle();

    final controller = Get.find<CustomerSavedAddressesController>();
    expect(controller.addresses, hasLength(2));

    await controller.selectAddressAPI(controller.addresses[1]);
    await tester.pumpAndSettle();

    expect(dataSource.defaultedIds, [2]);
    expect(dataSource.reportedLatitudes, [23.0225]);
    expect(Injector.customerLocationLabel, 'Office');
    expect(Injector.customerLatitude, 23.0225);
    // Stays on the screen, as in the patient app.
    expect(find.byType(CustomerSavedAddressesView), findsOneWidget);
  });
}
