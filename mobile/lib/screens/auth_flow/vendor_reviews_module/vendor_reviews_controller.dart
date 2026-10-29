import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// The vendor's own Reviews tab (SPEC section 3 item 8, task 4.8) —
/// view every review on their own listing (hidden or not, see
/// VendorReviewModel) and reply to them.
class VendorReviewsController extends GetxController {
  List<VendorReviewModel> reviews = [];

  bool isLoading = false;
  bool isLoadingMore = false;
  bool isSubmittingReply = false;
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
      final response = await DataSource.instance.vendorReviewsAPI(
        page: loadMore ? currentPage + 1 : 1,
      );

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      final fetched = (response.data as List<dynamic>)
          .map((e) => VendorReviewModel.fromJson(e as Map<String, dynamic>))
          .toList();

      reviews = loadMore ? [...reviews, ...fetched] : fetched;

      final meta = response.meta;
      currentPage = meta?['current_page'] as int? ?? 1;
      lastPage = meta?['last_page'] as int? ?? 1;
    } catch (e) {
      if (kDebugMode) {
        print('Vendor reviews error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      isLoadingMore = false;
      update();
    }
  }

  /// Patches the row locally on success rather than re-fetching the
  /// whole page — a reply doesn't change anything else about the list
  /// (rating, ordering), so a full re-fetch would just be a slower way
  /// to get the same one field updated.
  Future<bool> replyToReviewAPI(VendorReviewModel review, String reply) async {
    if (review.id == null) {
      return false;
    }

    isSubmittingReply = true;
    update();

    try {
      final response = await DataSource.instance.replyToReviewAPI(
        reviewId: review.id!,
        reply: reply,
      );

      if (response == null || !response.isSuccess) {
        Utils.showToast(response?.message ?? tr(StringRes.reviewSubmitFailed), isError: true);
        return false;
      }

      final data = response.data as Map<String, dynamic>;
      review.vendorReply = data['vendor_reply'] as String?;
      review.repliedAt = data['replied_at'] as String?;

      Utils.showToast(tr(StringRes.reviewSubmitted));
      return true;
    } catch (e) {
      if (kDebugMode) {
        print('Reply to review error $e');
      }
      Utils.showToast(tr(StringRes.reviewSubmitFailed), isError: true);
      return false;
    } finally {
      isSubmittingReply = false;
      update();
    }
  }
}
