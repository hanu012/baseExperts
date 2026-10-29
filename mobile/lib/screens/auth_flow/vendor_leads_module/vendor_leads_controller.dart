import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// The vendor Leads tab (SPEC section 3 item 7, task 4.8) — every
/// customer who tapped Call or WhatsApp, plus "Request a review"
/// (SPEC section 3 item 8), scoped to one specific lead.
class VendorLeadsController extends GetxController {
  List<LeadModel> leads = [];

  bool isLoading = false;
  bool isLoadingMore = false;
  int currentPage = 1;
  int lastPage = 1;

  /// Tracks which single lead's "Request a review" call is in flight,
  /// so only that row's button shows a spinner — not the whole list.
  int? requestingReviewForLeadId;

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
      final response = await DataSource.instance.vendorLeadsAPI(
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
          .map((e) => LeadModel.fromJson(e as Map<String, dynamic>))
          .toList();

      leads = loadMore ? [...leads, ...fetched] : fetched;

      final meta = response.meta;
      currentPage = meta?['current_page'] as int? ?? 1;
      lastPage = meta?['last_page'] as int? ?? 1;
    } catch (e) {
      if (kDebugMode) {
        print('Vendor leads error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      isLoadingMore = false;
      update();
    }
  }

  /// The specific "already requested"/"already reviewed"/"too old"
  /// rejection messages come straight from the server (SPEC section
  /// 3 item 8's eligibility rules) — surfaced as-is rather than a
  /// generic failure toast, since each is genuinely actionable
  /// information, not an apology.
  Future<void> requestReviewAPI(LeadModel lead) async {
    if (lead.id == null || requestingReviewForLeadId != null) {
      return;
    }

    requestingReviewForLeadId = lead.id;
    update();

    try {
      final response = await DataSource.instance.requestReviewAPI(leadId: lead.id!);

      if (response == null || !response.isSuccess) {
        Utils.showToast(
          response?.message ?? tr(StringRes.reviewRequestFailed),
          isError: true,
        );
        return;
      }

      lead.reviewRequestedAt = (response.data as Map<String, dynamic>)['review_requested_at'] as String?;
      Utils.showToast(tr(StringRes.reviewRequestSent));
    } catch (e) {
      if (kDebugMode) {
        print('Request review error $e');
      }
      Utils.showToast(tr(StringRes.reviewRequestFailed), isError: true);
    } finally {
      requestingReviewForLeadId = null;
      update();
    }
  }
}
