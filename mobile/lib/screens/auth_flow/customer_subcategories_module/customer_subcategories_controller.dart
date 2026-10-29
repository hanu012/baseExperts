import '../../../constants/app.export.dart';
import '../vendor_search_module/vendor_search_view.dart';

/// The four filter chips above the subcategory list.
enum ServiceTypeFilter { all, installation, repair, maintenance }

/// Subcategory list for a tapped category (SPEC section 4 items 3-4, task
/// 5.1). No fetch for the list itself — the category tree already arrived
/// fully loaded from GET /api/categories on the home screen, so the
/// tapped CategoryModel (subcategories included) is threaded straight
/// through the constructor. Vendor counts are the one thing this screen
/// does fetch — they are zone-specific, which the cached tree is not.
class CustomerSubcategoriesController extends GetxController {
  final CategoryModel category;
  final int? zoneId;
  final double? latitude;
  final double? longitude;
  final String? pincode;

  CustomerSubcategoriesController({
    required this.category,
    required this.zoneId,
    required this.latitude,
    required this.longitude,
    required this.pincode,
  });

  final TextEditingController searchController = TextEditingController();
  String query = '';
  ServiceTypeFilter typeFilter = ServiceTypeFilter.all;

  /// subcategory_id -> how many vendors cover it in the customer's zone.
  /// Absent (not merely 0) until fetchVendorCountsAPI() returns, so the
  /// list can show nothing rather than a wrong "0 vendors" while loading.
  Map<int, int> vendorCounts = {};
  bool isLoadingCounts = false;

  @override
  void onInit() {
    super.onInit();
    fetchVendorCountsAPI();
  }

  Future<void> fetchVendorCountsAPI() async {
    final categoryId = category.id;
    if (categoryId == null) {
      return;
    }

    isLoadingCounts = true;
    update();

    try {
      final response = await DataSource.instance.categoryVendorCountsAPI(
        categoryId: categoryId,
        latitude: latitude,
        longitude: longitude,
        pincode: pincode,
      );

      if (response == null || !response.isSuccess || response.data == null) {
        return;
      }

      final counts = (response.data as Map<String, dynamic>)['counts'];

      if (counts is Map<String, dynamic>) {
        vendorCounts = counts.map(
          (key, value) => MapEntry(int.parse(key), (value as num).toInt()),
        );
      }
    } catch (e) {
      if (kDebugMode) {
        print('Fetch vendor counts error $e');
      }
      // Silent otherwise: the list is still fully usable without counts,
      // and a toast over a secondary number is more annoying than useful.
    } finally {
      isLoadingCounts = false;
      update();
    }
  }

  void onQueryChanged(String value) {
    query = value;
    update();
  }

  void selectTypeFilter(ServiceTypeFilter filter) {
    typeFilter = filter;
    update();
  }

  /// What the chips were even built from — a chip for a type nothing in
  /// this category uses would just be a dead end.
  bool hasAnySubcategoryOfType(String type) =>
      category.subcategories.any((s) => s.serviceType == type);

  List<SubcategoryModel> get visibleSubcategories {
    final term = query.trim().toLowerCase();

    return category.subcategories.where((subcategory) {
      final matchesQuery = term.isEmpty ||
          (subcategory.name ?? '').toLowerCase().contains(term);

      final matchesType = typeFilter == ServiceTypeFilter.all ||
          subcategory.serviceType == typeFilter.name;

      return matchesQuery && matchesType;
    }).toList();
  }

  int vendorCountFor(SubcategoryModel subcategory) =>
      vendorCounts[subcategory.id] ?? 0;

  /// Vendor search entry point (SPEC section 4 item 4, task 5.3/5.4).
  /// `latitude`/`longitude`/`pincode` are the point/pincode task 4.6's
  /// location detection resolved on the home screen — GET
  /// /vendors/search re-resolves the authoritative zone from these
  /// itself, so nothing here needs to re-derive or re-validate it.
  void selectSubcategory(SubcategoryModel subcategory) {
    Get.to(() => VendorSearchView(
          subcategoryId: subcategory.id!,
          subcategoryName: subcategory.name,
          latitude: latitude,
          longitude: longitude,
          pincode: pincode,
        ));
  }

  @override
  void onClose() {
    searchController.dispose();
    super.onClose();
  }
}
