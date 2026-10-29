import 'package:easy_localization/easy_localization.dart';
import 'package:uuid/uuid.dart';

import '../../../constants/app.export.dart';
import '../subscription_confirmation_module/subscription_confirmation_view.dart';

/// Add Vendor, step 2b — categories/subcategories then zones, capped by the
/// plan chosen on the previous screen (SPEC section 2.2).
///
/// UX GUIDANCE ONLY. The counters here just stop an obviously-over-quota
/// submission before it's attempted — the real enforcer is the server-side
/// subscribe endpoint (SPEC section 6).
///
/// Selection model (confirmed, not inferred from SPEC): checking a
/// subcategory auto-selects its parent category; checking a category
/// cascades to select its subcategories up to the remaining subcategory
/// quota; unchecking a category cascades to deselect all its subcategories;
/// unchecking a subcategory leaves the category selected.
class SelectServicesController extends GetxController {
  final int vendorId;
  final PlanModel plan;
  final String businessName;
  final String loginEmail;

  /// A vendor who already had a plan: POST subscriptions/renew, no free
  /// trial, and a confirmation without login details.
  final bool isRenewal;

  SelectServicesController({
    required this.vendorId,
    required this.plan,
    required this.businessName,
    required this.loginEmail,
    this.isRenewal = false,
  });

  List<CategoryModel> categories = [];
  List<ZoneModel> zones = [];
  bool isLoading = false;
  bool isSubmitting = false;

  /// One key per screen, not per tap. The server stores it only on a
  /// successful subscribe, so a retry after a timeout (server done, app
  /// never heard) replays that success instead of failing as a second
  /// subscribe, while a retry after a real rejection is processed fresh.
  final String idempotencyKey = const Uuid().v4();

  final Set<int> selectedCategoryIds = {};
  final Set<int> selectedSubcategoryIds = {};
  final Set<int> selectedZoneIds = {};

  // Display-only state (search/filter/expand) — none of this affects what
  // gets selected or submitted, only what's visible while picking.
  final TextEditingController searchController = TextEditingController();
  String searchQuery = '';
  int? activeCategoryFilterId;
  final Set<int> expandedCategoryIds = {};

  /// 'cash', 'online', or 'free' — SPEC 2.2's third paid option, Cheque, has
  /// no backing enum value server-side (payments.mode is cash|online|free).
  String selectedPaymentMode = 'cash';

  /// Fallback matching the server's own default-if-unseeded value
  /// (Setting::get('free_trial_max_days', 15)) — used only in the unlikely
  /// case the dialog opens before fetchMasterDataAPI's settings call
  /// resolves.
  int freeTrialMaxDays = 15;

  /// Defaults to a modest 7, not the max: defaulting to the cap would make
  /// "grant the longest allowed trial" the path of least resistance instead
  /// of a deliberate choice.
  int freeTrialDays = 7;

  int get maxCategories => plan.maxCategories ?? 0;
  int get maxSubcategories => plan.maxSubcategories ?? 0;
  int get maxZones => plan.maxZones ?? 0;

  @override
  void onInit() {
    super.onInit();
    fetchMasterDataAPI();
  }

  @override
  void onClose() {
    searchController.dispose();
    zoneSearchController.dispose();
    super.onClose();
  }

  Future<void> fetchMasterDataAPI() async {
    isLoading = true;
    update();

    try {
      final responses = await Future.wait([
        DataSource.instance.categoriesAPI(),
        DataSource.instance.zonesAPI(),
        DataSource.instance.settingsAPI(),
      ]);

      final categoriesResponse = responses[0];
      final zonesResponse = responses[1];
      final settingsResponse = responses[2];

      if (categoriesResponse == null ||
          !categoriesResponse.isSuccess ||
          categoriesResponse.data == null ||
          zonesResponse == null ||
          !zonesResponse.isSuccess ||
          zonesResponse.data == null) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
        return;
      }

      categories = (categoriesResponse.data as List<dynamic>)
          .map((e) => CategoryModel.fromJson(e as Map<String, dynamic>))
          .toList();

      zones = (zonesResponse.data as List<dynamic>)
          .map((e) => ZoneModel.fromJson(e as Map<String, dynamic>))
          .toList();

      _applyFreeTrialCap(settingsResponse);
    } catch (e) {
      if (kDebugMode) {
        print('Fetch services master data error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
    } finally {
      isLoading = false;
      update();
    }
  }

  bool isCategorySelected(int categoryId) => selectedCategoryIds.contains(categoryId);

  bool isSubcategorySelected(int subcategoryId) =>
      selectedSubcategoryIds.contains(subcategoryId);

  bool isZoneSelected(int zoneId) => selectedZoneIds.contains(zoneId);

  void onSearchChanged(String value) {
    searchQuery = value.trim().toLowerCase();
    update();
  }

  /// null = "All" chip. Re-tapping the active chip clears the filter.
  void selectCategoryFilter(int? categoryId) {
    activeCategoryFilterId = activeCategoryFilterId == categoryId ? null : categoryId;
    update();
  }

  void toggleCategoryExpanded(int categoryId) {
    if (expandedCategoryIds.contains(categoryId)) {
      expandedCategoryIds.remove(categoryId);
    } else {
      expandedCategoryIds.add(categoryId);
    }
    update();
  }

  /// A search in progress force-expands every match — no point hiding a
  /// result the search itself just surfaced.
  bool isCategoryExpanded(int categoryId) =>
      searchQuery.isNotEmpty || expandedCategoryIds.contains(categoryId);

  /// Categories left standing after the chip filter and the search box —
  /// a category survives the search if its own name matches, or any of its
  /// subcategories' names do (so searching "gas" still surfaces "AC
  /// Service" for its "AC Gas Filling" child).
  List<CategoryModel> get filteredCategories {
    var list = categories;

    if (activeCategoryFilterId != null) {
      list = list.where((c) => c.id == activeCategoryFilterId).toList();
    }

    if (searchQuery.isEmpty) {
      return list;
    }

    return list.where((c) {
      final nameMatch = (c.name ?? '').toLowerCase().contains(searchQuery);
      final subMatch = c.subcategories.any(
        (s) => (s.name ?? '').toLowerCase().contains(searchQuery),
      );
      return nameMatch || subMatch;
    }).toList();
  }

  List<SubcategoryModel> visibleSubcategories(CategoryModel category) {
    if (searchQuery.isEmpty) {
      return category.subcategories;
    }
    return category.subcategories
        .where((s) => (s.name ?? '').toLowerCase().contains(searchQuery))
        .toList();
  }

  /// Headroom left under each cap, floored at 0 — the counters the screen
  /// puts in front of the salesman before they run out.
  int get subcategoriesRemaining =>
      (maxSubcategories - selectedSubcategoryIds.length).clamp(0, maxSubcategories);

  int get zonesRemaining => (maxZones - selectedZoneIds.length).clamp(0, maxZones);

  int selectedCountIn(CategoryModel category) => category.subcategories
      .where((sub) => selectedSubcategoryIds.contains(sub.id))
      .length;

  int selectedCountInCity(ZoneModel city) =>
      city.children.where((zone) => selectedZoneIds.contains(zone.id)).length;

  /// A zone with children is a city grouping (SPEC section 8's zone
  /// hierarchy); a childless top-level zone is itself a leaf and directly
  /// selectable, so it gets its own card rather than being dropped.
  List<ZoneModel> get zoneCities => zones.where((zone) => !zone.isLeaf).toList();

  List<ZoneModel> get standaloneZones => zones.where((zone) => zone.isLeaf).toList();

  /// The zones step runs its own search box, independent of the services
  /// one — the two screens narrow different lists and should not fight over
  /// a single query string.
  final TextEditingController zoneSearchController = TextEditingController();
  String zoneSearchQuery = '';

  void onZoneSearchChanged(String value) {
    zoneSearchQuery = value.trim().toLowerCase();
    update();
  }

  /// Cities that still have a matching area, with non-matching areas
  /// stripped out — searching "bopal" should surface Ahmedabad holding only
  /// Bopal, not the whole city.
  List<ZoneModel> get filteredZoneCities {
    if (zoneSearchQuery.isEmpty) {
      return zoneCities;
    }

    return zoneCities
        .where((city) => visibleZonesIn(city).isNotEmpty)
        .toList();
  }

  List<ZoneModel> visibleZonesIn(ZoneModel city) {
    if (zoneSearchQuery.isEmpty) {
      return city.children;
    }
    return city.children
        .where((zone) => (zone.name ?? '').toLowerCase().contains(zoneSearchQuery))
        .toList();
  }

  List<ZoneModel> get visibleStandaloneZones {
    if (zoneSearchQuery.isEmpty) {
      return standaloneZones;
    }
    return standaloneZones
        .where((zone) => (zone.name ?? '').toLowerCase().contains(zoneSearchQuery))
        .toList();
  }

  bool get categoryQuotaReached => selectedCategoryIds.length >= maxCategories;

  bool get subcategoryQuotaReached => selectedSubcategoryIds.length >= maxSubcategories;

  bool get zoneQuotaReached => selectedZoneIds.length >= maxZones;

  void toggleCategory(CategoryModel category) {
    final id = category.id;
    if (id == null) {
      return;
    }

    if (selectedCategoryIds.contains(id)) {
      selectedCategoryIds.remove(id);
      for (final sub in category.subcategories) {
        if (sub.id != null) {
          selectedSubcategoryIds.remove(sub.id);
        }
      }
      update();
      return;
    }

    if (categoryQuotaReached) {
      Utils.showToast(tr(StringRes.categoryQuotaReached), isError: true);
      return;
    }

    selectedCategoryIds.add(id);
    expandedCategoryIds.add(id);

    // Cascade-select subcategories up to the remaining quota. Any left over
    // stay unselected and read as quota-disabled in the UI — the same
    // general "checkbox disables once the counter is maxed" rule already
    // covers them, nothing extra to compute here.
    for (final sub in category.subcategories) {
      if (subcategoryQuotaReached) {
        break;
      }
      if (sub.id != null) {
        selectedSubcategoryIds.add(sub.id!);
      }
    }

    update();
  }

  void toggleSubcategory(SubcategoryModel subcategory, CategoryModel parent) {
    final id = subcategory.id;
    if (id == null) {
      return;
    }

    if (selectedSubcategoryIds.contains(id)) {
      selectedSubcategoryIds.remove(id);
      update();
      return;
    }

    if (subcategoryQuotaReached) {
      Utils.showToast(tr(StringRes.subcategoryQuotaReached), isError: true);
      return;
    }

    final parentId = parent.id;
    if (parentId != null && !selectedCategoryIds.contains(parentId)) {
      if (categoryQuotaReached) {
        Utils.showToast(tr(StringRes.categoryQuotaReached), isError: true);
        return;
      }
      selectedCategoryIds.add(parentId);
      expandedCategoryIds.add(parentId);
    }

    selectedSubcategoryIds.add(id);
    update();
  }

  void toggleZone(ZoneModel zone) {
    final id = zone.id;
    if (id == null) {
      return;
    }

    if (selectedZoneIds.contains(id)) {
      selectedZoneIds.remove(id);
      update();
      return;
    }

    if (zoneQuotaReached) {
      Utils.showToast(tr(StringRes.zoneQuotaReached), isError: true);
      return;
    }

    selectedZoneIds.add(id);
    update();
  }

  void selectPaymentMode(String mode) {
    selectedPaymentMode = mode;
    update();
  }

  /// Re-reads the cap each time the payment sheet opens, so a change an
  /// admin makes while the salesman is mid-flow shows up without leaving
  /// the screen. The sheet is a GetBuilder, so it redraws when this lands.
  Future<void> refreshFreeTrialCapAPI() async {
    try {
      _applyFreeTrialCap(await DataSource.instance.settingsAPI());
      update();
    } catch (e) {
      if (kDebugMode) {
        print('Refresh free trial cap error $e');
      }
    }
  }

  /// Best-effort: a failed settings fetch keeps the last known cap (15 by
  /// default) rather than blocking the screen over a non-essential value.
  void _applyFreeTrialCap(CommonResponse? response) {
    if (response == null || !response.isSuccess || response.data == null) {
      return;
    }

    final fetchedMax = (response.data as Map<String, dynamic>)['free_trial_max_days'];

    if (fetchedMax is int && fetchedMax > 0) {
      freeTrialMaxDays = fetchedMax;
      freeTrialDays = freeTrialDays.clamp(1, freeTrialMaxDays).toInt();
    }
  }

  void setFreeTrialDays(int days) {
    freeTrialDays = days.clamp(1, freeTrialMaxDays).toInt();
    update();
  }

  /// Selects every subcategory in one category that still fits under the
  /// plan's cap. Stops at the cap rather than refusing outright — a partial
  /// "select all" is the useful behaviour when 3 of 5 still fit.
  void selectAllIn(CategoryModel category) {
    final id = category.id;
    if (id == null) {
      return;
    }

    if (!selectedCategoryIds.contains(id)) {
      if (categoryQuotaReached) {
        Utils.showToast(tr(StringRes.categoryQuotaReached), isError: true);
        return;
      }
      selectedCategoryIds.add(id);
    }

    expandedCategoryIds.add(id);

    var hitCap = false;
    for (final sub in category.subcategories) {
      if (sub.id == null || selectedSubcategoryIds.contains(sub.id)) {
        continue;
      }
      if (subcategoryQuotaReached) {
        hitCap = true;
        break;
      }
      selectedSubcategoryIds.add(sub.id!);
    }

    if (hitCap) {
      Utils.showToast(tr(StringRes.subcategoryQuotaReached), isError: true);
    }

    update();
  }

  /// Selects every zone in one city that still fits under the plan's cap.
  void selectAllZonesIn(ZoneModel city) {
    var hitCap = false;
    for (final zone in city.children) {
      if (zone.id == null || selectedZoneIds.contains(zone.id)) {
        continue;
      }
      if (zoneQuotaReached) {
        hitCap = true;
        break;
      }
      selectedZoneIds.add(zone.id!);
    }

    if (hitCap) {
      Utils.showToast(tr(StringRes.zoneQuotaReached), isError: true);
    }

    update();
  }

  /// Gate for leaving the services step. Zones are not checked here — they
  /// are picked on the next screen, so blocking on them would be a dead end.
  bool validateServices() {
    if (selectedCategoryIds.isEmpty) {
      Utils.showToast(tr(StringRes.selectACategory), isError: true);
      return false;
    }

    if (selectedSubcategoryIds.isEmpty) {
      Utils.showToast(tr(StringRes.selectASubcategory), isError: true);
      return false;
    }

    return true;
  }

  /// Checked before the payment-mode dialog opens, so a doomed submission
  /// never gets that far. Re-checks the services step too: the user can walk
  /// back and clear it after having passed it once.
  bool validateSelections() {
    if (!validateServices()) {
      return false;
    }

    if (selectedZoneIds.isEmpty) {
      Utils.showToast(tr(StringRes.selectAZone), isError: true);
      return false;
    }

    return true;
  }

  /// Subscribes the vendor (SPEC section 6) — salesman-led only, straight
  /// to Active. The server re-validates everything checked client-side
  /// (quota, leaf-only zones, subcategory/category consistency) against
  /// current state, so a rejection here is possible even after passing
  /// validateSelections() (e.g. master data changed, or a stale screen).
  Future<void> subscribeAPI() async {
    isSubmitting = true;
    update();

    try {
      final body = {
        'vendor_id': vendorId,
        'plan_id': plan.id,
        'category_ids': selectedCategoryIds.toList(),
        'subcategory_ids': selectedSubcategoryIds.toList(),
        'zone_ids': selectedZoneIds.toList(),
        'payment_mode': selectedPaymentMode,
        if (selectedPaymentMode == 'free') 'free_trial_days': freeTrialDays,
      };

      Utils.showCircularProgressLottie(true);
      final response = isRenewal
          ? await DataSource.instance.renewSubscriptionAPI(body: body, idempotencyKey: idempotencyKey)
          : await DataSource.instance.subscribeAPI(body: body, idempotencyKey: idempotencyKey);
      Utils.showCircularProgressLottie(false);

      if (response == null || !response.isSuccess || response.data == null) {
        final fieldError = response?.fieldError('vendor_id') ??
            response?.fieldError('category_ids') ??
            response?.fieldError('subcategory_ids') ??
            response?.fieldError('zone_ids') ??
            response?.fieldError('free_trial_days');

        Utils.showToast(
          fieldError ?? response?.message ?? tr(StringRes.subscribeFailed),
          isError: true,
        );
        return;
      }

      final data = response.data as Map<String, dynamic>;
      final subscription = SubscriptionModel.fromJson(
        data['subscription'] as Map<String, dynamic>,
      );
      final temporaryPassword = data['temporary_password'] as String?;
      // Set instead of temporary_password when the server emailed the
      // vendor a "set your password" link (Firebase sign-in).
      final passwordLinkSentTo = data['password_link_sent_to'] as String?;

      // The draft is done — nothing left to resume. A renewal was never a
      // draft, and the stored draft may be a different vendor's.
      if (!isRenewal) {
        await Injector.prefs?.remove(PrefKeys.draftVendorId);
      }

      Get.to(() => SubscriptionConfirmationView(
            businessName: businessName,
            loginEmail: loginEmail,
            temporaryPassword: temporaryPassword ?? '',
            subscription: subscription,
            isRenewal: isRenewal,
            passwordLinkSentTo: passwordLinkSentTo ?? '',
          ));
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Subscribe error $e');
      }
      Utils.showToast(tr(StringRes.subscribeFailed), isError: true);
    } finally {
      isSubmitting = false;
      update();
    }
  }
}
