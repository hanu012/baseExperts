import 'package:easy_localization/easy_localization.dart';

import '../../../constants/app.export.dart';
import '../../../utils/location_capture.dart';
import '../customer_address_form_module/customer_address_form_view.dart';

/// Profile → My addresses (new location flow — see
/// Constants.useNewLocationFlow): the customer's address book.
///
/// Tapping an address makes it the current location right here, the way
/// the patient app does — it stays on this screen rather than closing, so
/// the "Selected" badge visibly moves.
class CustomerSavedAddressesController extends GetxController {
  /// Ahmedabad — where the seeded zones are. Used for a new address only
  /// when there is neither a stored location nor a GPS fix.
  static const double _fallbackLatitude = 23.0225;
  static const double _fallbackLongitude = 72.5714;

  List<CustomerAddressModel> addresses = [];
  bool isLoading = false;

  @override
  void onInit() {
    super.onInit();
    fetchAddressesAPI();
  }

  Future<void> fetchAddressesAPI() async {
    isLoading = true;
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
      isLoading = false;
      update();
    }
  }

  /// Opens the form where the customer already is: the stored location,
  /// else a GPS fix, else the fallback city.
  Future<void> addNewAddress() async {
    double? latitude = Injector.customerLatitude;
    double? longitude = Injector.customerLongitude;

    if (latitude == null || longitude == null) {
      final position = await LocationCapture.detect();
      latitude = position?.latitude ?? _fallbackLatitude;
      longitude = position?.longitude ?? _fallbackLongitude;
    }

    final saved = await Get.to<CustomerAddressModel?>(
      () => CustomerAddressFormView(latitude: latitude!, longitude: longitude!),
    );

    if (saved == null) {
      return;
    }

    // A new address saved as default is also where the customer is now.
    if (saved.isDefault) {
      await _applyAsCurrentLocation(saved);
    }

    fetchAddressesAPI();
  }

  /// Makes [address] the default and the current location: stored on the
  /// device for the home header, and sent to customers/me/location, which
  /// is what vendor matching reads.
  Future<void> selectAddressAPI(CustomerAddressModel address) async {
    if (address.id == null || !address.hasPoint) {
      return;
    }

    Utils.showCircularProgressLottie(true);
    await DataSource.instance.setDefaultCustomerAddressAPI(addressId: address.id!);
    await _applyAsCurrentLocation(address);
    Utils.showCircularProgressLottie(false);

    Utils.showToast(tr(StringRes.locationUpdated));
    fetchAddressesAPI();
  }

  Future<void> _applyAsCurrentLocation(CustomerAddressModel address) async {
    await Injector.setCustomerLocation(
      label: address.displayLabel ?? tr(StringRes.selectedLocationLabel),
      address: address.fullAddress,
      latitude: address.latitude!,
      longitude: address.longitude!,
    );

    await DataSource.instance.updateCustomerLocationAPI(
      latitude: address.latitude,
      longitude: address.longitude,
    );
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

    if (saved == null) {
      return;
    }

    // Editing the address in use moves the current location with it.
    if (saved.isDefault) {
      await _applyAsCurrentLocation(saved);
    }

    fetchAddressesAPI();
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

    // Removed from the list straight away, as in the patient app; the
    // refetch below restores it if the server refuses.
    addresses.remove(address);
    update();

    Utils.showCircularProgressLottie(true);
    final response = await DataSource.instance.deleteCustomerAddressAPI(addressId: address.id!);
    Utils.showCircularProgressLottie(false);

    if (response == null || !response.isSuccess) {
      Utils.showToast(response?.message ?? tr(StringRes.somethingWentWrong), isError: true);
    } else {
      Utils.showToast(tr(StringRes.addressDeleted));
    }

    fetchAddressesAPI();
  }
}
