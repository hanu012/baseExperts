import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_search_module/vendor_search_controller.dart';

/// Fakes GET /vendors/search's paginated response shape (task 5.3) —
/// two vendors per page, three pages total, so page 1 + one "load more"
/// covers the interesting cases without needing a real paginator.
class _PagedVendorSearchDataSource extends DataSource {
  bool zoneMatches = true;
  bool toggleShouldFail = false;
  List<int>? capturedPages;
  List<int> capturedToggleVendorIds = [];

  _PagedVendorSearchDataSource() : capturedPages = [];

  @override
  Future<CommonResponse?> toggleFavoriteAPI({required int vendorId}) async {
    capturedToggleVendorIds.add(vendorId);

    if (toggleShouldFail) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'NOT_FOUND', 'message': 'No customer profile.'},
      });
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {'is_favorite': true},
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> vendorSearchAPI({
    required int subcategoryId,
    double? latitude,
    double? longitude,
    String? pincode,
    int page = 1,
    int perPage = 15,
    String? sort,
  }) async {
    capturedPages!.add(page);

    if (!zoneMatches) {
      return CommonResponse.fromJson({
        'success': true,
        'data': {'zone': null, 'vendors': []},
        'error': null,
      });
    }

    final vendorsByPage = {
      1: [_vendor(1, 'Cool Air Services'), _vendor(2, 'Frost King AC')],
      2: [_vendor(3, 'Arctic Repairs')],
    };

    return CommonResponse.fromJson({
      'success': true,
      'data': {
        'zone': {'id': 7, 'name': 'Gota'},
        'vendors': vendorsByPage[page] ?? [],
      },
      'meta': {'current_page': page, 'per_page': 2, 'total': 3, 'last_page': 2},
      'error': null,
    });
  }

  Map<String, dynamic> _vendor(int id, String name) => {
        'id': id,
        'business_name': name,
        'address': '12 MG Road',
        'latitude': 23.02,
        'longitude': 72.52,
        'shop_photo_url': null,
        'rating_avg': 0,
        'rating_count': 0,
      };
}

void main() {
  testWidgets('fetchAPI populates vendors and the resolved zone', (tester) async {
    final fake = _PagedVendorSearchDataSource();
    DataSource.instance = fake;

    final controller = VendorSearchController(
      subcategoryId: 10,
      subcategoryName: 'Gas Filling',
      latitude: 23.02,
      longitude: 72.52,
      pincode: null,
    );
    await controller.fetchAPI();

    expect(controller.isLoading, isFalse);
    expect(controller.zone?.name, 'Gota');
    expect(controller.vendors, hasLength(2));
    expect(controller.vendors.first.businessName, 'Cool Air Services');
    expect(controller.hasMore, isTrue);
    expect(fake.capturedPages, [1]);

    controller.onClose();
  });

  testWidgets('load-more appends rather than replaces and requests the next page', (
    tester,
  ) async {
    final fake = _PagedVendorSearchDataSource();
    DataSource.instance = fake;

    final controller = VendorSearchController(
      subcategoryId: 10,
      subcategoryName: 'Gas Filling',
      latitude: 23.02,
      longitude: 72.52,
      pincode: null,
    );
    await controller.fetchAPI();
    await controller.fetchAPI(loadMore: true);

    expect(controller.vendors, hasLength(3));
    expect(controller.vendors.map((v) => v.id), [1, 2, 3]);
    expect(controller.hasMore, isFalse);
    expect(fake.capturedPages, [1, 2]);

    controller.onClose();
  });

  testWidgets('a zone that matches nothing leaves vendors empty without crashing', (
    tester,
  ) async {
    final fake = _PagedVendorSearchDataSource()..zoneMatches = false;
    DataSource.instance = fake;

    final controller = VendorSearchController(
      subcategoryId: 10,
      subcategoryName: 'Gas Filling',
      latitude: 10.0,
      longitude: 10.0,
      pincode: null,
    );
    await controller.fetchAPI();

    expect(controller.isLoading, isFalse);
    expect(controller.zone, isNull);
    expect(controller.vendors, isEmpty);
    expect(controller.hasMore, isFalse);

    controller.onClose();
  });

  testWidgets('toggleFavoriteAPI optimistically flips isFavorite then keeps it on success', (
    tester,
  ) async {
    final fake = _PagedVendorSearchDataSource();
    DataSource.instance = fake;

    final controller = VendorSearchController(
      subcategoryId: 10,
      subcategoryName: 'Gas Filling',
      latitude: 23.02,
      longitude: 72.52,
      pincode: null,
    );
    await controller.fetchAPI();
    final vendor = controller.vendors.first;
    expect(vendor.isFavorite, isFalse);

    await controller.toggleFavoriteAPI(vendor);

    expect(vendor.isFavorite, isTrue);
    expect(fake.capturedToggleVendorIds, [vendor.id]);

    controller.onClose();
  });

  testWidgets('toggleFavoriteAPI reverts the optimistic flip on failure', (tester) async {
    final fake = _PagedVendorSearchDataSource()..toggleShouldFail = true;
    DataSource.instance = fake;

    final controller = VendorSearchController(
      subcategoryId: 10,
      subcategoryName: 'Gas Filling',
      latitude: 23.02,
      longitude: 72.52,
      pincode: null,
    );
    await controller.fetchAPI();
    final vendor = controller.vendors.first;

    await controller.toggleFavoriteAPI(vendor);

    expect(vendor.isFavorite, isFalse);

    controller.onClose();
  });
}
