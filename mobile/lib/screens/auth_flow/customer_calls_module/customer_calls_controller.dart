import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// Profile → My calls: vendors the customer called or messaged, newest
/// first, with the approximate call length. Same "Load more" pagination
/// shape as CustomerFavoritesController.
class CustomerCallsController extends GetxController {
  List<CustomerCallModel> calls = [];

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
      final response = await DataSource.instance.customerCallsAPI(
        page: loadMore ? currentPage + 1 : 1,
      );

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(response?.message ?? tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      final page = (response.data as List<dynamic>)
          .map((e) => CustomerCallModel.fromJson(e as Map<String, dynamic>))
          .toList();

      calls = loadMore ? [...calls, ...page] : page;

      final meta = response.meta;
      currentPage = meta?['current_page'] as int? ?? 1;
      lastPage = meta?['last_page'] as int? ?? 1;
    } catch (e) {
      if (kDebugMode) {
        print('Fetch my calls error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      isLoadingMore = false;
      update();
    }
  }
}
