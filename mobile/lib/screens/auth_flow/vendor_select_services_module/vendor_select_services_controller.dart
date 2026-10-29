import 'package:easy_localization/easy_localization.dart';
import 'package:uuid/uuid.dart';

import '../../../constants/app.export.dart';
import '../vendor_dashboard_module/vendor_dashboard_view.dart';
import '../vendor_landing_module/vendor_landing_view.dart';

/// Vendor self-service, step 2 — categories/subcategories then zones,
/// capped by the plan chosen on the previous screen (SPEC section 3.2).
///
/// UX GUIDANCE ONLY, same as the salesman-flow SelectServicesController —
/// the real enforcer is the server-side subscribe endpoint. Fresh module
/// rather than a reuse of that controller: no payment-mode dialog here
/// (self-service is always payment_mode=online per SPEC section 3.2 — a
/// vendor cannot hand themselves cash, and a free trial is a
/// salesman-granted concept), and success lands on the dashboard, not a
/// WhatsApp confirmation screen.
///
/// EXTENDED IN PLACE for "edit services on an active plan" rather than
/// duplicated into a new module — the toggle/cascade/quota-cap logic below
/// is identical either way, it just starts from a pre-seeded selection
/// instead of an empty one. `isAddingMore: true` plus the three
/// `existing*Ids` sets is what the vendor dashboard's Services tab passes;
/// the initial-subscribe call site (vendor_select_plan_module) never sets
/// them.
///
/// Earlier picks are NOT locked: the vendor can untick them to free room
/// for something else. [addServicesAPI] sends the whole selection with
/// `replace: true`, and the server removes whatever was left out.
class VendorSelectServicesController extends GetxController {
  final int vendorId;
  final PlanModel plan;
  final bool isAddingMore;

  /// A vendor who already had a plan: POST subscriptions/renew.
  final bool isRenewal;
  final Set<int> existingCategoryIds;
  final Set<int> existingSubcategoryIds;
  final Set<int> existingZoneIds;

  VendorSelectServicesController({
    required this.vendorId,
    required this.plan,
    this.isAddingMore = false,
    this.isRenewal = false,
    Set<int>? existingCategoryIds,
    Set<int>? existingSubcategoryIds,
    Set<int>? existingZoneIds,
  })  : existingCategoryIds = existingCategoryIds ?? const {},
        existingSubcategoryIds = existingSubcategoryIds ?? const {},
        existingZoneIds = existingZoneIds ?? const {} {
    // Pre-seeding is synchronous set manipulation, not a network fetch — it
    // happens here rather than in onInit() so a test can exercise it
    // without also triggering fetchMasterDataAPI(), same as how the
    // initial-subscribe tests already construct this controller directly
    // without going through GetX's lifecycle at all.
    selectedCategoryIds.addAll(this.existingCategoryIds);
    selectedSubcategoryIds.addAll(this.existingSubcategoryIds);
    selectedZoneIds.addAll(this.existingZoneIds);
    // So "add more" mode opens showing what's already there, not collapsed
    // behind a tap.
    expandedCategoryIds.addAll(this.existingCategoryIds);
  }

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
  /// subcategories' names do.
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

  /// The zones step runs its own search box, independent of the services one.
  final TextEditingController zoneSearchController = TextEditingController();
  String zoneSearchQuery = '';

  void onZoneSearchChanged(String value) {
    zoneSearchQuery = value.trim().toLowerCase();
    update();
  }

  List<ZoneModel> get filteredZoneCities {
    if (zoneSearchQuery.isEmpty) {
      return zoneCities;
    }
    return zoneCities.where((city) => visibleZonesIn(city).isNotEmpty).toList();
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

  Future<void> fetchMasterDataAPI() async {
    isLoading = true;
    update();

    try {
      final responses = await Future.wait([
        DataSource.instance.categoriesAPI(),
        DataSource.instance.zonesAPI(),
      ]);

      final categoriesResponse = responses[0];
      final zonesResponse = responses[1];

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

  /// Headroom left under each cap, floored at 0.
  int get subcategoriesRemaining =>
      (maxSubcategories - selectedSubcategoryIds.length).clamp(0, maxSubcategories);

  int get zonesRemaining => (maxZones - selectedZoneIds.length).clamp(0, maxZones);

  int selectedCountIn(CategoryModel category) => category.subcategories
      .where((sub) => selectedSubcategoryIds.contains(sub.id))
      .length;

  int selectedCountInCity(ZoneModel city) =>
      city.children.where((zone) => selectedZoneIds.contains(zone.id)).length;

  /// A zone with children is a city grouping; a childless top-level zone is
  /// itself a leaf and directly selectable.
  List<ZoneModel> get zoneCities => zones.where((zone) => !zone.isLeaf).toList();

  List<ZoneModel> get standaloneZones => zones.where((zone) => zone.isLeaf).toList();

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

      // A category is selected through its subcategories, so unticking the
      // last one drops the category too — otherwise it would keep using a
      // category slot with nothing under it.
      final parentId = parent.id;
      if (parentId != null && selectedCountIn(parent) == 0) {
        selectedCategoryIds.remove(parentId);
      }

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

  /// Selects every subcategory in one category that still fits under the
  /// plan's cap. Stops at the cap rather than refusing outright.
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

  /// Subscribes the vendor to themselves (SPEC section 3.2) —
  /// payment_mode is always 'online', never a choice on this screen. The
  /// server re-validates everything checked client-side, and independently
  /// enforces that this vendor_id can only be the caller's own.
  Future<void> subscribeAPI() async {
    if (!validateSelections()) {
      return;
    }

    isSubmitting = true;
    update();

    try {
      final body = {
        'vendor_id': vendorId,
        'plan_id': plan.id,
        'category_ids': selectedCategoryIds.toList(),
        'subcategory_ids': selectedSubcategoryIds.toList(),
        'zone_ids': selectedZoneIds.toList(),
        'payment_mode': 'online',
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
            response?.fieldError('payment_mode');

        Utils.showToast(
          fieldError ?? response?.message ?? tr(StringRes.subscribeFailed),
          isError: true,
        );
        return;
      }

      final subscription = (response.data as Map<String, dynamic>)['subscription'];
      final awaitingPayment = subscription is Map<String, dynamic> &&
          SubscriptionModel.fromJson(subscription).isAwaitingPayment;

      // Razorpay on: a payment link went out by SMS and nothing is active
      // yet. The landing gate routes to "Waiting for payment" from what
      // GET /vendors/me reports, the same as on any later app start.
      // A renewal can start from the dashboard (Renew / Upgrade), which is
      // still mounted underneath — replacing the stack with a second
      // dashboard is the crash CLAUDE.md warns about, so it goes through
      // the landing gate, which then opens a fresh dashboard.
      Utils.transitionWithOffAll(
        awaitingPayment || isRenewal ? const VendorLandingView() : const VendorDashboardView(),
      );
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

  /// How many earlier picks the current selection drops.
  int get removedCount =>
      existingCategoryIds.difference(selectedCategoryIds).length +
      existingSubcategoryIds.difference(selectedSubcategoryIds).length +
      existingZoneIds.difference(selectedZoneIds).length;

  /// Whether anything differs from what the subscription already covers.
  bool get hasChanges =>
      removedCount > 0 ||
      selectedCategoryIds.difference(existingCategoryIds).isNotEmpty ||
      selectedSubcategoryIds.difference(existingSubcategoryIds).isNotEmpty ||
      selectedZoneIds.difference(existingZoneIds).isNotEmpty;

  /// Saves the vendor's edited selection on their OWN already-active
  /// subscription (SPEC section 3.3) — no vendor_id, no plan_id, no
  /// payment_mode: everything resolves server-side from the caller's own
  /// current subscription.
  ///
  /// Sends the WHOLE selection with `replace: true`: new ticks are added,
  /// unticked earlier picks are removed. The server re-checks the result
  /// against the plan's limits. Removing anything is confirmed first.
  Future<void> addServicesAPI() async {
    if (!validateSelections()) {
      return;
    }

    if (!hasChanges) {
      Utils.showToast(tr(StringRes.noServiceChanges), isError: true);
      return;
    }

    final removed = removedCount;

    if (removed > 0) {
      final confirmed = await confirmRemoval(removed);

      if (!confirmed) {
        return;
      }
    }

    isSubmitting = true;
    update();

    // Set once the pickers are closed: this controller is deleted along
    // with the services step, so it must not update() itself afterwards.
    var closed = false;

    try {
      final body = {
        'replace': true,
        'category_ids': selectedCategoryIds.toList(),
        'subcategory_ids': selectedSubcategoryIds.toList(),
        'zone_ids': selectedZoneIds.toList(),
      };

      Utils.showCircularProgressLottie(true);
      final response = await DataSource.instance.addServicesAPI(body: body);
      Utils.showCircularProgressLottie(false);

      if (response == null || !response.isSuccess || response.data == null) {
        final fieldError = response?.fieldError('category_ids') ??
            response?.fieldError('subcategory_ids') ??
            response?.fieldError('zone_ids') ??
            response?.fieldError('subscription');

        Utils.showToast(
          fieldError ?? response?.message ?? tr(StringRes.subscribeFailed),
          isError: true,
        );
        return;
      }

      Utils.showToast(tr(StringRes.servicesUpdated));
      isSubmitting = false;
      closed = true;
      closePickers();
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Add services error $e');
      }
      Utils.showToast(tr(StringRes.subscribeFailed), isError: true);
    } finally {
      if (!closed) {
        isSubmitting = false;
        update();
      }
    }
  }

  /// Back to the dashboard's Services tab after a save.
  ///
  /// Both picker steps are closed — zones, then services. Closing only the
  /// top one (what this used to do) left the vendor on the services step
  /// with Continue leading straight back to zones, round and round, and
  /// the Services tab did not refresh until they backed out twice. It
  /// refreshes as soon as the services step closes (see
  /// VendorServicesView.addMoreServices). Stops at the first route, so it
  /// can never close the dashboard itself.
  @visibleForTesting
  void closePickers() {
    var popped = 0;
    Get.until((route) => popped++ >= 2 || route.isFirst);
  }

  /// Removing a service takes the vendor out of those customers' search
  /// results, so it is said plainly before it happens.
  ///
  /// A seam rather than an inline dialog so tests can answer it without a
  /// navigator.
  @visibleForTesting
  Future<bool> Function(int removedCount) confirmRemoval = (removedCount) => showCustomerConfirmDialog(
        title: tr(StringRes.removeServicesTitle),
        message: tr(StringRes.removeServicesDesc, args: ['$removedCount']),
        confirmText: tr(StringRes.saveChanges),
        cancelText: tr(StringRes.cancel),
        danger: true,
      );
}
