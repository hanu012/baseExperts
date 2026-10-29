import 'dart:async';

import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../utils/place_service.dart';

/// "Select location" (SPEC section 4.2) — the customer choosing where
/// they are shopping from, by typing a place, using GPS, or dropping a
/// pin on a map.
///
/// Returns the chosen [PlaceSuggestion] through `Get.back(result:)`
/// rather than writing it itself. The two screens this one opens do the
/// same, so a choice made three screens deep unwinds back to whoever
/// asked for a location, and only that caller decides what to do with
/// it — which is what lets the same screen serve the home header today
/// and the profile's "Saved location" row as well.
class CustomerSelectLocationController extends GetxController {
  final TextEditingController searchController = TextEditingController();

  List<PlaceSuggestion> results = [];
  bool isSearching = false;

  /// Set once a search has run, so the empty list reads as "nothing
  /// matched" rather than "type something" on the first frame.
  bool hasSearched = false;

  /// Coalesces keystrokes into one request. Autocomplete is billed per
  /// session rather than per request, but an unthrottled field still
  /// fires a request per character and the responses race each other —
  /// the slower one can land last and overwrite the newer results.
  Timer? _debounce;

  /// Guards against exactly that race: only the newest query is allowed
  /// to publish its results.
  int _requestId = 0;

  bool get hasQuery => searchController.text.trim().isNotEmpty;

  void onQueryChanged(String value) {
    _debounce?.cancel();

    if (value.trim().length < 2) {
      results = [];
      hasSearched = false;
      isSearching = false;
      update();
      return;
    }

    isSearching = true;
    update();

    _debounce = Timer(const Duration(milliseconds: 350), () => _search(value));
  }

  Future<void> _search(String query) async {
    final id = ++_requestId;
    final found = await PlaceService.instance.search(query);

    // A newer query has already been issued — this response is stale.
    if (id != _requestId) {
      return;
    }

    results = found;
    hasSearched = true;
    isSearching = false;
    update();
  }

  void clearQuery() {
    searchController.clear();
    results = [];
    hasSearched = false;
    isSearching = false;
    update();
  }

  /// Resolves the tapped suggestion to a point and hands it back.
  ///
  /// A suggestion without coordinates is useless to vendor search, so a
  /// failed resolve stays on this screen rather than returning something
  /// the caller cannot use.
  Future<void> selectSuggestion(PlaceSuggestion suggestion) async {
    Utils.showCircularProgressLottie(true);
    final resolved = await PlaceService.instance.resolve(suggestion);
    Utils.showCircularProgressLottie(false);

    if (resolved == null || !resolved.hasPoint) {
      Utils.showToast(tr(StringRes.locationNotResolved), isError: true);
      return;
    }

    Get.back(result: resolved);
  }

  @override
  void onClose() {
    _debounce?.cancel();
    // Ends the Places billing session if the customer left without
    // choosing anything.
    PlaceService.instance.endSession();
    searchController.dispose();
    super.onClose();
  }
}
