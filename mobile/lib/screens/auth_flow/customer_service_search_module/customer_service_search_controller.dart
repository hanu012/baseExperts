import 'dart:async';

import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';

/// One row in the results: a service and the category it sits under.
///
/// Built either from the loaded category tree (the browse list shown
/// before anything is typed) or from a search response, so it carries
/// plain fields rather than wrapping either model.
class ServiceHit {
  const ServiceHit({
    required this.id,
    required this.name,
    required this.categoryName,
  });

  final int? id;
  final String name;
  final String categoryName;
}

/// Searching for a service by name (SPEC section 4 items 3-4).
///
/// Queries `GET /api/services/search`, which ranks prefix matches first
/// and matches the parent category too, so "AC" finds every AC service
/// and "gas" finds Gas Filling ahead of AC Gas Filling. The server is
/// the authority: it searches the live catalogue rather than whatever
/// subset this screen happens to be holding.
///
/// Before anything is typed the screen shows the already-loaded tree.
/// That costs no request and makes the screen useful as a
/// browse-everything list.
class CustomerServiceSearchController extends GetxController {
  CustomerServiceSearchController({required this.categories});

  /// The tree handed down from home, already fetched — the pre-query
  /// browse list.
  final List<CategoryModel> categories;

  final TextEditingController searchController = TextEditingController();

  String query = '';
  List<ServiceHit> results = [];
  bool isSearching = false;

  /// Set once a search has returned, so an empty list reads as "nothing
  /// matched" rather than "type something".
  bool hasSearched = false;

  /// Coalesces keystrokes into one request, and stops the app firing a
  /// query per character at the server.
  Timer? _debounce;

  /// Only the newest query may publish its results — a slower earlier
  /// response must not land last and overwrite them.
  int _requestId = 0;

  bool get hasQuery => query.trim().isNotEmpty;

  /// Everything in the loaded tree, flattened. Shown until the customer
  /// types something.
  late final List<ServiceHit> browseList = [
    for (final category in categories)
      for (final subcategory in category.subcategories)
        ServiceHit(
          id: subcategory.id,
          name: subcategory.name ?? '',
          categoryName: category.name ?? '',
        ),
  ];

  /// What the list renders: search results once there is a query, the
  /// whole catalogue before that.
  List<ServiceHit> get visible => hasQuery ? results : browseList;

  void onQueryChanged(String value) {
    query = value;
    _debounce?.cancel();

    // The endpoint requires two characters; below that there is nothing
    // to ask for, so fall back to the browse list.
    if (value.trim().length < 2) {
      results = [];
      hasSearched = false;
      isSearching = false;
      update();
      return;
    }

    isSearching = true;
    update();

    _debounce = Timer(
      const Duration(milliseconds: 300),
      () => searchServicesAPI(value),
    );
  }

  Future<void> searchServicesAPI(String term) async {
    final id = ++_requestId;

    try {
      final response = await DataSource.instance.searchServicesAPI(query: term);

      // A newer query has been issued — this response is stale.
      if (id != _requestId) {
        return;
      }

      if (response == null || !response.isSuccess || response.data == null) {
        results = [];
        hasSearched = true;
        return;
      }

      final data = response.data as Map<String, dynamic>;
      final services = (data['services'] as List<dynamic>?) ?? [];

      results = services.map((raw) {
        final item = raw as Map<String, dynamic>;

        return ServiceHit(
          id: item['id'] as int?,
          name: item['name'] as String? ?? '',
          categoryName: item['category_name'] as String? ?? '',
        );
      }).toList();

      hasSearched = true;
    } catch (e) {
      if (id != _requestId) {
        return;
      }

      if (kDebugMode) {
        print('Service search error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
      results = [];
      hasSearched = true;
    } finally {
      if (id == _requestId) {
        isSearching = false;
        update();
      }
    }
  }

  void clearQuery() {
    _debounce?.cancel();
    searchController.clear();
    query = '';
    results = [];
    hasSearched = false;
    isSearching = false;
    update();
  }

  @override
  void onClose() {
    _debounce?.cancel();
    searchController.dispose();
    super.onClose();
  }
}
