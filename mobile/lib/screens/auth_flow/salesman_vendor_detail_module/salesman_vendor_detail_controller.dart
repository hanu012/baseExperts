import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../vendor_flow/select_plan_module/select_plan_view.dart';
import '../../vendor_flow/vendor_documents_module/vendor_documents_view.dart';

/// One of the salesman's own vendors, in full (SPEC section 2.3) — what
/// they sold them and how much of it is used.
///
/// Backed by `GET /api/vendors/{id}`, which returns the same
/// `{vendor, active_subscription}` shape as `GET /vendors/me`, so
/// [VendorMeModel] parses it unchanged rather than needing a second model
/// that could drift from it. `active_subscription` is null for a draft or
/// lapsed vendor, which is the "Not subscribed" state the list already
/// shows — not an error.
class SalesmanVendorDetailController extends GetxController {
  final int vendorId;

  SalesmanVendorDetailController({required this.vendorId});

  VendorMeModel? vendor;
  bool isLoading = false;
  bool hasError = false;

  @override
  void onInit() {
    super.onInit();
    fetchVendorAPI();
  }

  /// Lapsed (grace or expired) or inside the last 15 days — the same window
  /// the vendor's own app offers Renew in. Days left carry over, so early
  /// renewal loses nothing.
  bool get canRenew {
    final current = vendor;

    if (current == null) {
      return false;
    }

    if (current.status == 'grace' || current.status == 'expired') {
      return true;
    }

    final days = current.activeSubscription?.daysRemaining;

    return current.status == 'active' && days != null && days <= 15;
  }

  /// Plan → services → payment, the same steps as adding a vendor, in
  /// renewal mode. Refreshes on return so a renewed plan shows straight
  /// away.
  Future<void> openRenew() async {
    final current = vendor;

    if (current == null) {
      return;
    }

    await Get.to(() => SelectPlanView(
          vendorId: vendorId,
          businessName: current.businessName ?? '',
          loginEmail: current.email ?? '',
          isRenewal: true,
        ));

    fetchVendorAPI();
  }

  /// The vendor's KYC documents — the same screen the vendor app uses,
  /// scoped to this vendor. Admin approves the vendor only once every
  /// compulsory document is verified, so this is where a salesman follows
  /// up on a missing or rejected one.
  void openDocuments() {
    Get.to(() => VendorDocumentsView(vendorId: vendorId));
  }

  Future<void> fetchVendorAPI() async {
    isLoading = true;
    hasError = false;
    update();

    try {
      final response = await DataSource.instance.vendorShowAPI(vendorId: vendorId);

      if (response == null || !response.isSuccess || response.data == null) {
        hasError = true;
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      vendor = VendorMeModel.fromJson(response.data as Map<String, dynamic>);
    } catch (e) {
      if (kDebugMode) {
        print('Fetch vendor detail error $e');
      }
      hasError = true;
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      update();
    }
  }
}
