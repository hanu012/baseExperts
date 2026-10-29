import 'dart:async';

import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../vendor_flow/vendor_documents_module/vendor_documents_view.dart';
import '../vendor_login_module/vendor_login_view.dart';

/// Vendor dashboard (SPEC section 3.2/3.9) — plan name, quota used/total
/// per resource, days remaining. Shown once GET /api/vendors/me reports an
/// active subscription (task 4.2).
///
/// Leads and reviews live on their own tabs (task 4.8's
/// vendor_leads_module/vendor_reviews_module), not summarized here. No
/// aggregate rating figure on this screen specifically — `rating_avg`/
/// `rating_count` are real and live (task 5.5), just not surfaced on
/// this particular view; see VendorSearchService for where they're used.
/// Fetches its own data on every entry (not just what login handed
/// forward), so returning to the app later still shows live numbers.
class VendorDashboardController extends GetxController {
  VendorMeModel? vendorMe;
  bool isLoading = false;

  /// Recent calls card (admin switch "Call tracking in apps").
  LeadsSummaryModel? callsSummary;
  List<LeadModel> recentLeads = [];

  /// Only used to name the next tier up in the Overview upsell card.
  List<PlanModel> plans = [];

  /// Which of the five bottom-bar destinations is showing. Held here
  /// rather than in a TabController so the shell can stay a
  /// StatelessWidget like every other view in this app.
  int currentTab = 0;

  @override
  void onInit() {
    super.onInit();
    fetchVendorMeAPI();
  }

  void selectTab(int index) {
    if (index == currentTab) {
      return;
    }

    currentTab = index;
    update();
  }

  /// Two letters for the hero's avatar tile, from the business name.
  String get initials {
    final parts = (vendorMe?.businessName ?? '')
        .trim()
        .split(RegExp(r'\s+'))
        .where((part) => part.isNotEmpty)
        .toList();

    if (parts.isEmpty) {
      return '?';
    }

    if (parts.length == 1) {
      final one = parts.first;

      return (one.length >= 2 ? one.substring(0, 2) : one).toUpperCase();
    }

    return '${parts.first[0]}${parts[1][0]}'.toUpperCase();
  }

  Future<void> fetchVendorMeAPI() async {
    isLoading = true;
    update();

    try {
      final response = await DataSource.instance.vendorMeAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        Utils.showToast(
          response?.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return;
      }

      vendorMe = VendorMeModel.fromJson(response.data as Map<String, dynamic>);

      // Fire-and-forget: the upsell card is the only thing that needs
      // these, so a failure to load them hides that card rather than
      // failing the dashboard the vendor actually came for.
      unawaited(fetchPlansAPI());

      // Same reasoning: a failure hides the Recent calls card only.
      if (Injector.callTrackingEnabled) {
        unawaited(fetchRecentCallsAPI());
      }

      // And again: a failure just leaves the documents notice out.
      unawaited(fetchDocumentsAttentionAPI());
    } catch (e) {
      if (kDebugMode) {
        print('Fetch vendor dashboard error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      update();
    }
  }

  /// KYC documents that need the vendor now: sent back by the admin, past
  /// their expiry, or a compulsory one not verified (a type the admin made
  /// compulsory later). Empty when all is well — the notice is then hidden.
  /// Already-approved vendors are otherwise only told by a push.
  List<String> documentsNeedingAttention = [];

  Future<void> fetchDocumentsAttentionAPI() async {
    try {
      final response = await DataSource.instance.vendorDocumentsAPI();

      if (response == null || !response.isSuccess || response.data is! Map<String, dynamic>) {
        return;
      }

      final data = VendorDocumentsModel.fromJson(response.data as Map<String, dynamic>);

      documentsNeedingAttention = {
        for (final document in data.documents)
          if (document.status == VendorDocumentModel.statusReupload ||
              document.status == VendorDocumentModel.statusExpired)
            document.documentType.name ?? '',
        ...data.missingCompulsory,
      }.where((name) => name.isNotEmpty).toList();
      update();
    } catch (e) {
      if (kDebugMode) {
        print('Vendor dashboard documents error $e');
      }
    }
  }

  /// Pushed over the dashboard, which stays mounted; re-read on return.
  Future<void> openDocuments() async {
    await Get.to(() => const VendorDocumentsView());
    await fetchDocumentsAttentionAPI();
  }

  /// Today's counts and the last three leads for the home Recent calls card.
  Future<void> fetchRecentCallsAPI() async {
    try {
      final responses = await Future.wait([
        DataSource.instance.vendorLeadsSummaryAPI(),
        DataSource.instance.vendorLeadsAPI(page: 1, perPage: 3),
      ]);

      final summary = responses[0];
      final leads = responses[1];

      if (summary != null && summary.isSuccess && summary.data is Map<String, dynamic>) {
        callsSummary = LeadsSummaryModel.fromJson(summary.data as Map<String, dynamic>);
      }

      if (leads != null && leads.isSuccess && leads.data is List) {
        recentLeads = (leads.data as List)
            .map((e) => LeadModel.fromJson(e as Map<String, dynamic>))
            .toList();
      }
    } catch (e) {
      if (kDebugMode) {
        print('Recent calls error $e');
      }
    } finally {
      update();
    }
  }

  /// Plans, purely so the dashboard can name the next tier up.
  Future<void> fetchPlansAPI() async {
    try {
      final response = await DataSource.instance.plansAPI();

      if (response == null || !response.isSuccess || response.data == null) {
        return;
      }

      plans = (response.data as List<dynamic>)
          .map((e) => PlanModel.fromJson(e as Map<String, dynamic>))
          .toList();

      update();
    } catch (e) {
      if (kDebugMode) {
        print('Fetch plans for upsell error $e');
      }
    }
  }

  /// The cheapest plan that costs more than the one they are on, or null
  /// when they are already on the top tier (or plans failed to load).
  ///
  /// Matched by name because that is all ActiveSubscriptionModel carries —
  /// there is no plan id on the subscription summary. A rename server-side
  /// would drop the upsell rather than recommend the wrong plan, which is
  /// the right way round for something that quotes prices.
  PlanModel? get upgradePlan {
    final currentName = vendorMe?.activeSubscription?.planName;

    if (currentName == null || plans.isEmpty) {
      return null;
    }

    final current = plans.where((p) => p.name == currentName).firstOrNull;

    if (current == null) {
      return null;
    }

    final dearer = plans
        .where((p) => (p.pricePaise ?? 0) > (current.pricePaise ?? 0))
        .toList()
      ..sort((a, b) => (a.pricePaise ?? 0).compareTo(b.pricePaise ?? 0));

    return dearer.firstOrNull;
  }

  Future<void> logoutAPI() async {
    try {
      Utils.showCircularProgressLottie(true);
      await DataSource.instance.logoutAPI();
      Utils.showCircularProgressLottie(false);
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Vendor logout error $e');
      }
    }

    await Injector.clearUserData();

    Utils.showToast(tr(StringRes.logout));
    Utils.transitionWithOffAll(const VendorLoginView());
  }
}
