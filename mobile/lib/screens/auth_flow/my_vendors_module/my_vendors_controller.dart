import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../vendor_flow/select_plan_module/select_plan_view.dart';

/// Salesman home, My Vendors tab (SPEC section 2.3): vendor name, plan,
/// days to expiry. No leads column — Phase 5's leads table doesn't exist
/// yet, and the backend deliberately omits the field rather than sending a
/// fake zero (see SalesmanVendorResource on the backend).
class MyVendorsController extends GetxController {
  List<SalesmanVendorModel> vendors = [];
  bool isLoading = false;

  /// Display-only narrowing. Client-side, like the services screen's
  /// search: this endpoint returns a salesman's whole (bounded) vendor
  /// list in one call, so filtering locally is instant and needs no
  /// debounce or round trip.
  final TextEditingController searchController = TextEditingController();
  String searchQuery = '';

  /// Off shows everyone; on narrows to vendors with no live subscription —
  /// the salesman's actual work queue.
  bool unsubscribedOnly = false;

  @override
  void onInit() {
    super.onInit();
    fetchVendorsAPI();
  }

  @override
  void onClose() {
    searchController.dispose();
    super.onClose();
  }

  void onSearchChanged(String value) {
    searchQuery = value.trim().toLowerCase();
    update();
  }

  void toggleUnsubscribedOnly() {
    unsubscribedOnly = !unsubscribedOnly;
    update();
  }

  void clearFilters() {
    searchController.clear();
    searchQuery = '';
    unsubscribedOnly = false;
    update();
  }

  bool get hasActiveFilter => searchQuery.isNotEmpty || unsubscribedOnly;

  List<SalesmanVendorModel> get filteredVendors {
    var list = vendors;

    if (unsubscribedOnly) {
      list = list.where((v) => !v.isSubscribed).toList();
    }

    if (searchQuery.isEmpty) {
      return list;
    }

    // Owner name too, not just the business: a salesman often remembers
    // the person before the shop.
    return list.where((v) {
      final business = (v.businessName ?? '').toLowerCase();
      final owner = (v.ownerName ?? '').toLowerCase();
      return business.contains(searchQuery) || owner.contains(searchQuery);
    }).toList();
  }

  /// Picks an unfinished onboarding back up at the step it stopped at.
  ///
  /// A draft row means the details step completed and nothing after it
  /// did, so the step to land on is always plan selection — see
  /// SalesmanVendorModel.isDraft for why there is no other half-finished
  /// state to distinguish.
  ///
  /// The list payload carries the business name but not the login email
  /// (SalesmanVendorResource does not expose it), and the plan screen
  /// needs both to hand on to Subscribe, so the vendor is re-read here
  /// rather than guessed at.
  Future<void> resumeDraftAPI(SalesmanVendorModel vendor) async {
    final vendorId = vendor.id;

    if (vendorId == null) {
      return;
    }

    try {
      Utils.showCircularProgressLottie(true);
      final response = await DataSource.instance.vendorShowAPI(vendorId: vendorId);
      Utils.showCircularProgressLottie(false);

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      final data = (response.data as Map<String, dynamic>)['vendor'];

      if (data is! Map<String, dynamic>) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      await Get.to(() => SelectPlanView(
            vendorId: vendorId,
            businessName: (data['business_name'] as String?) ?? vendor.businessName ?? '',
            loginEmail: (data['email'] as String?) ?? '',
          ));

      // They may have finished the sale while they were in there, which
      // changes this row's status and plan.
      await fetchVendorsAPI();
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Resume draft error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    }
  }

  Future<void> fetchVendorsAPI() async {
    isLoading = true;
    update();

    try {
      final response = await DataSource.instance.salesmanVendorsAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      vendors = (response.data as List<dynamic>)
          .map((e) => SalesmanVendorModel.fromJson(e as Map<String, dynamic>))
          .toList();
    } catch (e) {
      if (kDebugMode) {
        print('Fetch my vendors error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      update();
    }
  }
}
