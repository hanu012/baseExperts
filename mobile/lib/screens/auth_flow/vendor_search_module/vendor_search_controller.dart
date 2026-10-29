import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// Vendor search results (SPEC section 4 item 4, task 5.3/5.4). Location
/// is whatever `CustomerHomeController` last resolved (task 4.6) — passed
/// straight through, not re-resolved here, since `GET /vendors/search`
/// takes an explicit point/pincode and re-resolves the authoritative zone
/// itself server-side.
class VendorSearchController extends GetxController {
  final int subcategoryId;
  final String? subcategoryName;
  final double? latitude;
  final double? longitude;
  final String? pincode;

  VendorSearchController({
    required this.subcategoryId,
    required this.subcategoryName,
    required this.latitude,
    required this.longitude,
    required this.pincode,
  });

  List<VendorSearchModel> vendors = [];
  ResolvedZoneModel? zone;

  bool isLoading = false;
  bool isLoadingMore = false;
  int currentPage = 1;
  int lastPage = 1;

  bool get hasMore => currentPage < lastPage;

  @override
  void onInit() {
    super.onInit();
    fetchAPI();
  }

  Future<void> fetchAPI({bool loadMore = false}) async {
    if (loadMore) {
      isLoadingMore = true;
    } else {
      isLoading = true;
      currentPage = 1;
    }
    update();

    try {
      final response = await DataSource.instance.vendorSearchAPI(
        subcategoryId: subcategoryId,
        latitude: latitude,
        longitude: longitude,
        pincode: pincode,
        page: loadMore ? currentPage + 1 : 1,
      );

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      final result = VendorSearchResultModel.fromJson(response.data as Map<String, dynamic>);
      zone = result.zone;

      vendors = loadMore ? [...vendors, ...result.vendors] : result.vendors;

      final meta = response.meta;
      currentPage = meta?['current_page'] as int? ?? 1;
      lastPage = meta?['last_page'] as int? ?? 1;
    } catch (e) {
      if (kDebugMode) {
        print('Vendor search error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      isLoadingMore = false;
      update();
    }
  }

  /// Optimistic: flips the icon immediately, reverts on failure. A
  /// customer only reachable if logged in (this screen is public), but
  /// the toggle route itself is customer-only — a guest tap should not
  /// even be wired to this in the view.
  Future<void> toggleFavoriteAPI(VendorSearchModel vendor) async {
    final previous = vendor.isFavorite;
    vendor.isFavorite = !previous;
    update();

    try {
      final response = await DataSource.instance.toggleFavoriteAPI(vendorId: vendor.id ?? 0);

      if (response == null || !response.isSuccess) {
        vendor.isFavorite = previous;
        Utils.showToast(response?.message ?? tr(StringRes.somethingWentWrong), isError: true);
      }
    } catch (e) {
      vendor.isFavorite = previous;
      if (kDebugMode) {
        print('Toggle favorite error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      update();
    }
  }
}
