import 'dart:async';

import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../utils/location_capture.dart';
import '../../../utils/place_service.dart';
import '../customer_address_form_module/customer_address_form_view.dart';

/// "Set your location" — the new customer location picker (see
/// Constants.useNewLocationFlow), modelled on the patient app: search, use
/// the current location, or tap a saved address.
///
/// Search and current location both go through the confirm-and-save form,
/// so every location a customer sets ends up in their address book.
///
/// Returns a [PlaceSuggestion] through `Get.back(result:)` — the same
/// contract as the old CustomerSelectLocationView — so the home header and
/// profile handle either screen's answer with the same code.
class CustomerSetLocationController extends GetxController {
  final TextEditingController searchController = TextEditingController();

  List<PlaceSuggestion> results = [];
  bool isSearching = false;
  bool hasSearched = false;

  List<CustomerAddressModel> addresses = [];
  bool isLoadingAddresses = false;

  /// Coalesces keystrokes into one request, and [_requestId] lets only the
  /// newest one publish — the same guards as the old search screen.
  Timer? _debounce;
  int _requestId = 0;

  bool get hasQuery => searchController.text.trim().isNotEmpty;

  @override
  void onInit() {
    super.onInit();
    fetchAddressesAPI();
  }

  Future<void> fetchAddressesAPI() async {
    isLoadingAddresses = true;
    update();

    try {
      final response = await DataSource.instance.customerAddressesAPI();

      if (response != null && response.isSuccess && response.data is List) {
        addresses = (response.data as List)
            .map((e) => CustomerAddressModel.fromJson(e as Map<String, dynamic>))
            .toList();
      }
    } catch (e) {
      if (kDebugMode) {
        print('Fetch addresses error $e');
      }
    } finally {
      isLoadingAddresses = false;
      update();
    }
  }

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

  /// Search hit → resolve to a point → confirm on the map and save.
  Future<void> selectSuggestion(PlaceSuggestion suggestion) async {
    Utils.showCircularProgressLottie(true);
    final resolved = await PlaceService.instance.resolve(suggestion);
    Utils.showCircularProgressLottie(false);

    if (resolved == null || !resolved.hasPoint) {
      Utils.showToast(tr(StringRes.locationNotResolved), isError: true);
      return;
    }

    await _confirmAndFinish(
      latitude: resolved.latitude!,
      longitude: resolved.longitude!,
      searchedAddress: resolved.fullAddress,
    );
  }

  /// GPS fix → confirm on the map and save.
  Future<void> useCurrentLocation() async {
    final position = await LocationCapture.detect();

    if (position == null) {
      return;
    }

    await _confirmAndFinish(latitude: position.latitude, longitude: position.longitude);
  }

  Future<void> _confirmAndFinish({
    required double latitude,
    required double longitude,
    String? searchedAddress,
  }) async {
    final saved = await Get.to<CustomerAddressModel?>(
      () => CustomerAddressFormView(
        latitude: latitude,
        longitude: longitude,
        searchedAddress: searchedAddress,
      ),
    );

    if (saved != null) {
      _finishWith(saved);
    }
  }

  /// A saved address is used as it is: marked default server-side (best
  /// effort — the location itself still applies if that call fails) and
  /// handed back.
  Future<void> selectSavedAddressAPI(CustomerAddressModel address) async {
    if (!address.hasPoint || address.id == null) {
      return;
    }

    Utils.showCircularProgressLottie(true);
    await DataSource.instance.setDefaultCustomerAddressAPI(addressId: address.id!);
    Utils.showCircularProgressLottie(false);

    _finishWith(address);
  }

  Future<void> editAddress(CustomerAddressModel address) async {
    if (!address.hasPoint) {
      return;
    }

    final saved = await Get.to<CustomerAddressModel?>(
      () => CustomerAddressFormView(
        latitude: address.latitude!,
        longitude: address.longitude!,
        editing: address,
      ),
    );

    if (saved != null) {
      fetchAddressesAPI();
    }
  }

  Future<void> deleteAddressAPI(CustomerAddressModel address) async {
    final confirmed = await showCustomerConfirmDialog(
      title: tr(StringRes.deleteAddressTitle),
      message: tr(StringRes.deleteAddressDesc),
      confirmText: tr(StringRes.deleteAction),
      cancelText: tr(StringRes.cancel),
      danger: true,
    );

    if (!confirmed || address.id == null) {
      return;
    }

    Utils.showCircularProgressLottie(true);
    final response = await DataSource.instance.deleteCustomerAddressAPI(addressId: address.id!);
    Utils.showCircularProgressLottie(false);

    if (response == null || !response.isSuccess) {
      Utils.showToast(response?.message ?? tr(StringRes.somethingWentWrong), isError: true);
      return;
    }

    Utils.showToast(tr(StringRes.addressDeleted));
    fetchAddressesAPI();
  }

  void _finishWith(CustomerAddressModel address) {
    Get.back(
      result: PlaceSuggestion(
        id: 'address:${address.id}',
        title: address.displayLabel ?? tr(StringRes.selectedLocationLabel),
        subtitle: address.fullAddress,
        latitude: address.latitude,
        longitude: address.longitude,
      ),
    );
  }

  @override
  void onClose() {
    _debounce?.cancel();
    // Ends the Places billing session if the customer left mid-search.
    PlaceService.instance.endSession();
    searchController.dispose();
    super.onClose();
  }
}
