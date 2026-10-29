import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// Profile → My reviews: the reviews the customer has written, newest
/// first. Same "Load more" pagination shape as CustomerCallsController.
class CustomerReviewsController extends GetxController {
  List<CustomerReviewModel> reviews = [];

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
      final response = await DataSource.instance.customerReviewsAPI(
        page: loadMore ? currentPage + 1 : 1,
      );

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(response?.message ?? tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      final page = (response.data as List<dynamic>)
          .map((e) => CustomerReviewModel.fromJson(e as Map<String, dynamic>))
          .toList();

      reviews = loadMore ? [...reviews, ...page] : page;

      final meta = response.meta;
      currentPage = meta?['current_page'] as int? ?? 1;
      lastPage = meta?['last_page'] as int? ?? 1;
    } catch (e) {
      if (kDebugMode) {
        print('Fetch my reviews error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      isLoadingMore = false;
      update();
    }
  }
}
