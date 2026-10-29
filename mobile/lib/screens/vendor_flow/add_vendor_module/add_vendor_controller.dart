import 'dart:io';

import 'package:easy_localization/easy_localization.dart';
import 'package:image_picker/image_picker.dart';

import '../../../constants/app.export.dart';
import '../../../utils/location_capture.dart';
import '../../../utils/place_service.dart';
import '../../auth_flow/customer_select_location_module/customer_select_location_view.dart';
import '../select_plan_module/select_plan_view.dart';
import '../vendor_kyc_stepper_module/vendor_kyc_stepper_view.dart';

/// Add Vendor, step 1 — business info and KYC (SPEC section 2.2).
///
/// THE DRAFT IS SAVED BEFORE PAYMENT ON PURPOSE. Salesmen work in the field
/// on unreliable networks, so the typed details are persisted server-side as
/// soon as they are valid, and the returned id is kept locally. A dropped
/// connection then resumes rather than restarting — resubmitting the same
/// details returns the same draft instead of a duplicate error.
///
/// The shop photo is a second call for the same reason: a slow or failed
/// photo costs the upload, not the business details.
///
/// Identity documents are not on this form any more. They are per-type KYC
/// documents (admin decides the types and which are compulsory), uploaded
/// from the shared documents screen once the draft exists. They never block
/// plan selection — the vendor simply stays pending verification until
/// admin has verified every compulsory one.
class AddVendorController extends GetxController {
  TextEditingController businessNameController = TextEditingController();
  TextEditingController ownerNameController = TextEditingController();
  TextEditingController phoneController = TextEditingController();
  TextEditingController emailController = TextEditingController();
  TextEditingController addressController = TextEditingController();

  GlobalKey<FormState> formKey = GlobalKey<FormState>();
  AutovalidateMode autoValidateMode = AutovalidateMode.disabled;

  /// Set once the draft exists server-side. Its presence is what makes this
  /// screen resumable.
  int? draftVendorId;

  String? shopPhotoPath;

  /// The draft's KYC documents, for the "1 of 2 compulsory uploaded" line.
  /// Null until a draft exists and has been read.
  VendorDocumentsModel? documentsSummary;

  double? latitude;
  double? longitude;
  bool isCapturingLocation = false;

  final ImagePicker _picker = ImagePicker();

  @override
  void onInit() {
    super.onInit();
    _restoreDraft();
  }

  /// Picks the in-progress draft back up after the app was killed mid-flow.
  Future<void> _restoreDraft() async {
    final storedId = Injector.prefs?.getInt(PrefKeys.draftVendorId);

    if (storedId == null) {
      return;
    }

    draftVendorId = storedId;
    update();

    try {
      final response = await DataSource.instance.vendorShowAPI(vendorId: storedId);

      if (response == null || !response.isSuccess || response.data == null) {
        // The draft is gone server-side (deleted, or belongs to someone
        // else). Drop the stale id rather than blocking the salesman on a
        // record they cannot reach.
        await _forgetDraft();
        return;
      }

      final vendor = response.data['vendor'] as Map<String, dynamic>;

      businessNameController.text = (vendor['business_name'] ?? '') as String;
      ownerNameController.text = (vendor['owner_name'] ?? '') as String;
      phoneController.text = (vendor['phone'] ?? '') as String;
      emailController.text = (vendor['email'] ?? '') as String;
      addressController.text = (vendor['address'] ?? '') as String;
      update();

      await fetchDocumentsSummaryAPI();
    } catch (e) {
      if (kDebugMode) {
        print('Restore draft error $e');
      }
    }
  }

  Future<void> _rememberDraft(int id) async {
    draftVendorId = id;
    await Injector.prefs?.setInt(PrefKeys.draftVendorId, id);
    update();
  }

  Future<void> _forgetDraft() async {
    draftVendorId = null;
    await Injector.prefs?.remove(PrefKeys.draftVendorId);
    update();
  }

  Future<void> pickShopPhoto() async {
    final file = await _pickImage();

    if (file != null) {
      shopPhotoPath = file.path;
      update();
    }
  }

  /// Quiet: the progress line is a convenience, so a failure just leaves it
  /// out rather than toasting over the form.
  Future<void> fetchDocumentsSummaryAPI() async {
    final id = draftVendorId;

    if (id == null) {
      return;
    }

    try {
      final response = await DataSource.instance.vendorDocumentsAPI(vendorId: id);

      if (response != null && response.isSuccess && response.data is Map<String, dynamic>) {
        documentsSummary = VendorDocumentsModel.fromJson(response.data as Map<String, dynamic>);
        update();
      }
    } catch (e) {
      if (kDebugMode) {
        print('Fetch draft documents error $e');
      }
    }
  }

  /// Opens the KYC stepper (one step per compulsory document still to
  /// upload, then a review) for this vendor, with "Skip for now"; the
  /// progress line re-reads whatever it returns. The documents
  /// hang off the vendor record, so a draft that does not exist yet is
  /// saved first (without moving on to plan selection).
  Future<void> openDocuments() async {
    var id = draftVendorId;

    id ??= await _saveDraft();

    if (id == null) {
      return;
    }

    await Get.to(() => VendorKycStepperView(vendorId: id, allowSkip: true));
    await fetchDocumentsSummaryAPI();
  }

  bool get hasLocation => latitude != null && longitude != null;

  /// Captures the shop's GPS location (SPEC section 2.2). Best-effort: the
  /// server accepts a draft without coordinates, so a denial here leaves the
  /// salesman able to continue rather than blocking the save.
  ///
  /// The actual service/permission/fix flow lives in LocationCapture
  /// (task 4.6), shared with the customer home screen's location
  /// detection rather than duplicated here.
  Future<void> captureLocation() async {
    isCapturingLocation = true;
    update();

    final position = await LocationCapture.detect();

    if (position != null) {
      latitude = position.latitude;
      longitude = position.longitude;

      // Fills the address only when the salesman has not typed one — a
      // hand-written address (shop number, landmark) beats a geocoded one.
      if (addressController.text.trim().isEmpty) {
        final place = await PlaceService.instance.addressAt(
          position.latitude,
          position.longitude,
        );

        if (place != null) {
          addressController.text = place.fullAddress;
        }
      }
    }

    isCapturingLocation = false;
    update();
  }

  /// Search the shop's address with Places autocomplete. Reuses the
  /// customer location picker, which only returns the chosen place and
  /// saves nothing itself; it also offers GPS and a map pin.
  Future<void> searchShopAddress() async {
    final place = await Get.to<PlaceSuggestion?>(
      () => const CustomerSelectLocationView(),
    );

    if (place == null || !place.hasPoint) {
      return;
    }

    latitude = place.latitude;
    longitude = place.longitude;
    addressController.text = place.fullAddress;
    update();
  }

  Future<XFile?> _pickImage() async {
    try {
      // Constrained at capture: these are photographed on a phone in the
      // field, and a full-resolution camera file would exceed the server's
      // 8 MB cap and take far longer over a weak connection.
      return await _picker.pickImage(
        source: ImageSource.gallery,
        maxWidth: 1600,
        imageQuality: 80,
      );
    } catch (e) {
      if (kDebugMode) {
        print('Image pick error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
      return null;
    }
  }

  /// Saves the draft, uploads the shop photo if one is attached, then moves
  /// on to plan selection. KYC documents are not required to continue.
  Future<void> saveDraftAPI() async {
    final id = await _saveDraft();

    if (id == null) {
      return;
    }

    await _uploadShopPhotoIfAny();

    Utils.showToast(tr(StringRes.draftSaved));
    Get.to(() => SelectPlanView(
          vendorId: id,
          businessName: businessNameController.text.trim(),
          loginEmail: emailController.text.trim(),
        ));
  }

  /// Validates and saves (or resumes) the draft. Returns its id, or null
  /// when the form is invalid or the server refused — a toast says which.
  Future<int?> _saveDraft() async {
    if (!(formKey.currentState?.validate() ?? false)) {
      autoValidateMode = AutovalidateMode.onUserInteraction;
      update();
      return null;
    }

    try {
      final body = {
        'business_name': businessNameController.text.trim(),
        'owner_name': ownerNameController.text.trim(),
        'phone': phoneController.text.trim(),
        'email': emailController.text.trim(),
        'address': addressController.text.trim(),
        if (latitude != null) 'latitude': latitude,
        if (longitude != null) 'longitude': longitude,
      };

      Utils.showCircularProgressLottie(true);
      CommonResponse? response =
          await DataSource.instance.vendorDraftAPI(body: body);
      Utils.showCircularProgressLottie(false);

      if (response == null) {
        Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
        return null;
      }

      if (!response.isSuccess || response.data == null) {
        // Duplicate email/phone comes back per-field, so mark the offending
        // input rather than showing one generic banner.
        final emailError = response.fieldError('email');
        final phoneError = response.fieldError('phone');

        Utils.showToast(
          emailError ?? phoneError ?? response.message ?? tr(StringRes.somethingWentWrong),
          isError: true,
        );
        return null;
      }

      final vendor = response.data['vendor'] as Map<String, dynamic>;
      await _rememberDraft(vendor['id'] as int);

      // A resumed draft means an earlier attempt did reach the server, even
      // if its response did not reach us. Say so rather than letting it look
      // like a fresh save.
      if (response.data['resumed'] == true) {
        Utils.showToast(tr(StringRes.draftResumed));
      }

      return vendor['id'] as int;
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('Save vendor draft error $e');
      }
      Utils.showToast(tr(StringRes.somethingWentWrong), isError: true);
      return null;
    }
  }

  /// Uploaded separately so a failure here never costs the saved draft.
  Future<void> _uploadShopPhotoIfAny() async {
    if (draftVendorId == null || shopPhotoPath == null) {
      return;
    }

    try {
      Utils.showCircularProgressLottie(true);
      final response = await DataSource.instance.vendorKycAPI(
        vendorId: draftVendorId!,
        shopPhotoPath: shopPhotoPath!,
      );
      Utils.showCircularProgressLottie(false);

      if (response == null || !response.isSuccess) {
        // The draft is safe either way — that is the point of the split.
        Utils.showToast(
          response?.message ?? tr(StringRes.kycUploadFailed),
          isError: true,
        );
      }
    } catch (e) {
      Utils.showCircularProgressLottie(false);
      if (kDebugMode) {
        print('KYC upload error $e');
      }
      Utils.showToast(tr(StringRes.kycUploadFailed), isError: true);
    }
  }

  String? validateRequired(String? value, String messageKey) {
    if ((value ?? '').trim().isEmpty) {
      return tr(messageKey);
    }

    return null;
  }

  String? validateEmail(String? value) {
    final email = (value ?? '').trim();

    if (email.isEmpty) {
      return tr(StringRes.enterYourEmail);
    }

    if (!GetUtils.isEmail(email)) {
      return tr(StringRes.invalidEmail);
    }

    return null;
  }

  String? validatePhone(String? value) {
    final phone = (value ?? '').trim();

    if (phone.isEmpty) {
      return tr(StringRes.enterPhone);
    }

    if (!GetUtils.isPhoneNumber(phone)) {
      return tr(StringRes.invalidPhone);
    }

    return null;
  }

  bool get hasShopPhoto => shopPhotoPath != null;

  File? get shopPhotoFile => shopPhotoPath == null ? null : File(shopPhotoPath!);

  @override
  void onClose() {
    businessNameController.dispose();
    ownerNameController.dispose();
    phoneController.dispose();
    emailController.dispose();
    addressController.dispose();
    super.onClose();
  }
}
