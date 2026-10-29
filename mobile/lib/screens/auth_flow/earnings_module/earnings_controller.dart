import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// Salesman home, Earnings tab (SPEC section 2.4): pending/paid commission
/// totals only.
///
/// Deliberately no monthly target vs. achieved, and no cash-collected-but-
/// unreconciled figure — both flagged as separate, undecided scope rather
/// than guessed at (see SalesmanController::commissions() on the backend).
class EarningsController extends GetxController {
  CommissionSummaryModel? summary;
  bool isLoading = false;

  @override
  void onInit() {
    super.onInit();
    fetchCommissionsAPI();
  }

  Future<void> fetchCommissionsAPI() async {
    isLoading = true;
    update();

    try {
      final response = await DataSource.instance.salesmanCommissionsAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      summary = CommissionSummaryModel.fromJson(response.data as Map<String, dynamic>);
    } catch (e) {
      if (kDebugMode) {
        print('Fetch earnings error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      update();
    }
  }
}
