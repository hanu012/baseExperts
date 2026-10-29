import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/customer_favorites_module/customer_favorites_controller.dart';
import 'package:service_marketplace/screens/auth_flow/customer_home_module/customer_home_controller.dart';

/// Fakes GET /customers/me/favorites (SPEC section 4 item 10) — two
/// vendors per page, two pages total, plus a configurable
/// POST .../favorite (toggle-off) outcome.
class _FakeCustomerFavoritesDataSource extends DataSource {
  List<int> capturedPages = [];
  bool toggleShouldFail = false;
  List<int> capturedToggleVendorIds = [];

  @override
  Future<CommonResponse?> favoritesAPI({int page = 1, int perPage = 15}) async {
    capturedPages.add(page);

    final vendorsByPage = {
      1: [_vendor(1, 'Cool Air Services'), _vendor(2, 'Frost King AC')],
      2: [_vendor(3, 'Arctic Repairs')],
    };

    return CommonResponse.fromJson({
      'success': true,
      'data': vendorsByPage[page] ?? [],
      'meta': {'current_page': page, 'per_page': 2, 'total': 3, 'last_page': 2},
      'error': null,
    });
  }

  @override
  Future<CommonResponse?> toggleFavoriteAPI({required int vendorId}) async {
    capturedToggleVendorIds.add(vendorId);

    if (toggleShouldFail) {
      return CommonResponse.fromJson({
        'success': false,
        'data': null,
        'error': {'code': 'SERVER_ERROR', 'message': 'Something broke'},
      });
    }

    return CommonResponse.fromJson({
      'success': true,
      'data': {'is_favorite': false},
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
        'is_favorite': true,
      };
}

void main() {
  testWidgets('fetchAPI populates favorited vendors', (tester) async {
    final fake = _FakeCustomerFavoritesDataSource();
    DataSource.instance = fake;

    final controller = CustomerFavoritesController();
    await controller.fetchAPI();

    expect(controller.isLoading, isFalse);
    expect(controller.vendors, hasLength(2));
    expect(controller.vendors.first.businessName, 'Cool Air Services');
    expect(controller.hasMore, isTrue);
    expect(fake.capturedPages, [1]);

    controller.onClose();
  });

  testWidgets('load-more appends rather than replaces and requests the next page', (
    tester,
  ) async {
    final fake = _FakeCustomerFavoritesDataSource();
    DataSource.instance = fake;

    final controller = CustomerFavoritesController();
    await controller.fetchAPI();
    await controller.fetchAPI(loadMore: true);

    expect(controller.vendors, hasLength(3));
    expect(controller.hasMore, isFalse);
    expect(fake.capturedPages, [1, 2]);

    controller.onClose();
  });

  testWidgets('toggleFavoriteAPI removes the row on success, since this list IS the set', (
    tester,
  ) async {
    final fake = _FakeCustomerFavoritesDataSource();
    DataSource.instance = fake;

    final controller = CustomerFavoritesController();
    await controller.fetchAPI();
    final vendor = controller.vendors.first;

    await controller.toggleFavoriteAPI(vendor);

    expect(controller.vendors, hasLength(1));
    expect(controller.vendors.contains(vendor), isFalse);
    expect(fake.capturedToggleVendorIds, [vendor.id]);

    controller.onClose();
  });

  /// The Favourites tab lives in the home IndexedStack and is never
  /// rebuilt, so its onInit fetch ran once: a vendor favourited later never
  /// appeared. Switching to the tab must reload it.
  testWidgets('switching to the Favourites tab reloads the list', (tester) async {
    final fake = _FakeCustomerFavoritesDataSource();
    DataSource.instance = fake;

    Get.put(CustomerFavoritesController()); // onInit → first fetch
    await tester.pump();
    expect(fake.capturedPages, [1]);

    final home = CustomerHomeController(); // not put: no location/GPS work
    home.changeTab(CustomerHomeController.favoritesTab);
    await tester.pump();

    expect(home.tabIndex, CustomerHomeController.favoritesTab);
    expect(fake.capturedPages, [1, 1]);

    // Other tabs do not refetch it.
    home.changeTab(0);
    await tester.pump();
    expect(fake.capturedPages, [1, 1]);

    Get.reset();
  });

  testWidgets('toggleFavoriteAPI re-inserts the row on failure', (tester) async {
    final fake = _FakeCustomerFavoritesDataSource()..toggleShouldFail = true;
    DataSource.instance = fake;

    final controller = CustomerFavoritesController();
    await controller.fetchAPI();
    final vendor = controller.vendors.first;

    await controller.toggleFavoriteAPI(vendor);

    expect(controller.vendors, hasLength(2));
    expect(controller.vendors.contains(vendor), isTrue);

    controller.onClose();
  });
}
