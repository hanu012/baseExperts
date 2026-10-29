import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// The customer's own favorited vendors (SPEC section 4 item 10) — same
/// "Load more" pagination shape as VendorSearchController/
/// VendorLeadsController.
class CustomerFavoritesController extends GetxController {
  List<VendorSearchModel> vendors = [];

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
      final response = await DataSource.instance.favoritesAPI(
        page: loadMore ? currentPage + 1 : 1,
      );

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(response?.message ?? tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      final page = (response.data as List<dynamic>)
          .map((e) => VendorSearchModel.fromJson(e as Map<String, dynamic>))
          .toList();

      vendors = loadMore ? [...vendors, ...page] : page;

      final meta = response.meta;
      currentPage = meta?['current_page'] as int? ?? 1;
      lastPage = meta?['last_page'] as int? ?? 1;
    } catch (e) {
      if (kDebugMode) {
        print('Fetch favorites error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      isLoadingMore = false;
      update();
    }
  }

  /// Optimistic, same shape as VendorSearchController's own toggle —
  /// but on this screen "unfavorited" means the row should disappear,
  /// not just flip its icon, since this list IS the favorites set.
  Future<void> toggleFavoriteAPI(VendorSearchModel vendor) async {
    final index = vendors.indexOf(vendor);
    vendors.remove(vendor);
    update();

    try {
      final response = await DataSource.instance.toggleFavoriteAPI(vendorId: vendor.id ?? 0);

      if (response == null || !response.isSuccess) {
        vendors.insert(index.clamp(0, vendors.length), vendor);
        Utils.showToast(response?.message ?? tr(StringRes.somethingWentWrong), isError: true);
      }
    } catch (e) {
      vendors.insert(index.clamp(0, vendors.length), vendor);
      if (kDebugMode) {
        print('Toggle favorite error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      update();
    }
  }
}
