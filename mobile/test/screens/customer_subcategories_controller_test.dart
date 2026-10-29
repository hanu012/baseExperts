import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:service_marketplace/common_model/category_model.dart';
import 'package:service_marketplace/common_model/common_response.dart';
import 'package:service_marketplace/network/data_source.dart';
import 'package:service_marketplace/screens/auth_flow/customer_subcategories_module/customer_subcategories_controller.dart';
import 'package:service_marketplace/screens/auth_flow/vendor_search_module/vendor_search_view.dart';

/// Fails the test outright if categoriesAPI() is ever called — the whole
/// point of this screen is that it needs no fetch, since the category
/// tree (subcategories included) already arrived on the home screen.
///
/// vendorSearchAPI() is stubbed to resolve immediately rather than left
/// to hit the real network: selectSubcategory() navigates to
/// VendorSearchView, whose controller fetches in onInit(), and an
/// unstubbed real Dio call in a widget test risks a slow/hanging
/// connection attempt rather than a clean, fast failure.
class _NoNetworkDataSource extends DataSource {
  @override
  Future<CommonResponse?> categoriesAPI() async {
    fail('CustomerSubcategoriesController must not call categoriesAPI()');
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
  }) async =>
      null;
}

CategoryModel _categoryWithSubcategories() {
  return CategoryModel.fromJson({
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
      {
        'id': 11,
        'category_id': 1,
        'name': 'Compressor Repair',
        'slug': 'compressor-repair',
        'icon_url': null,
        'sort_order': 2,
      },
    ],
  });
}

void main() {
  setUp(() {
    DataSource.instance = _NoNetworkDataSource();
  });

  testWidgets('subcategories come from the constructor-supplied category with no network call', (
    tester,
  ) async {
    final category = _categoryWithSubcategories();
    final controller = CustomerSubcategoriesController(
      category: category,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
      pincode: null,
    );

    expect(controller.visibleSubcategories, hasLength(2));
    expect(controller.visibleSubcategories.first.name, 'Gas Filling');
    expect(controller.visibleSubcategories.last.name, 'Compressor Repair');
  });

  testWidgets('selectSubcategory navigates to vendor search with the threaded location', (
    tester,
  ) async {
    final category = _categoryWithSubcategories();
    final controller = CustomerSubcategoriesController(
      category: category,
      zoneId: 7,
      latitude: 23.02,
      longitude: 72.52,
      pincode: null,
    );

    await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));

    controller.selectSubcategory(controller.visibleSubcategories.first);
    await tester.pumpAndSettle();

    final view = tester.widget<VendorSearchView>(find.byType(VendorSearchView));
    expect(view.subcategoryId, 10);
    expect(view.subcategoryName, 'Gas Filling');
    expect(view.latitude, 23.02);
    expect(view.longitude, 72.52);
    expect(view.pincode, null);
  });

  testWidgets('selectSubcategory threads a pincode-only location just as well', (tester) async {
    final category = _categoryWithSubcategories();
    final controller = CustomerSubcategoriesController(
      category: category,
      zoneId: null,
      latitude: null,
      longitude: null,
      pincode: '380001',
    );

    await tester.pumpWidget(GetMaterialApp(home: const Scaffold(body: SizedBox())));

    controller.selectSubcategory(controller.visibleSubcategories.first);
    await tester.pumpAndSettle();

    final view = tester.widget<VendorSearchView>(find.byType(VendorSearchView));
    expect(view.latitude, null);
    expect(view.longitude, null);
    expect(view.pincode, '380001');
  });
}
