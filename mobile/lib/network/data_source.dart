import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../common_model/common_response.dart';
import '../utils/injector.dart';

/// Single place every API call goes through, per CLAUDE.md: one named method
/// per endpoint on this singleton. No per-feature API classes, no generic
/// repository layer.
///
/// Endpoints mirror the backend built in tasks 0.2 and 0.3. The demo-app's
/// OTP endpoints (send-otp, verify-otp, login-with-phone) are deliberately
/// absent — CLAUDE.md forbids OTP/phone auth; this project is email + password.
class DataSource {
  static DataSource instance = DataSource();

  /// 10.0.2.2 is the host machine as seen from the Android emulator.
  /// Overridable at build time:
  ///   flutter run --dart-define=API_BASE_URL=http://192.168.1.5:8000/api/
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/',
  );

  // ── Endpoints ────────────────────────────────────────────────────────────
  static const String register = 'auth/register';
  static const String login = 'auth/login';
  static const String logout = 'auth/logout';
  static const String changePassword = 'auth/change-password';
  static const String passwordResetRequests = 'auth/password-reset-requests';
  static const String user = 'user';
  static const String vendors = 'vendors';
  static const String vendorDraft = 'vendors/draft';
  static const String categories = 'categories';
  static const String serviceSearch = 'services/search';
  static const String plans = 'plans';
  static const String zones = 'zones';
  static const String subscriptions = 'subscriptions';
  static const String settings = 'settings';
  static const String salesmanMe = 'salesmen/me';
  static const String userPreferences = 'user/preferences';
  static const String userProfile = 'user/profile';
  static const String userAvatar = 'user/avatar';
  static const String broadcastingAuth = 'broadcasting/auth';
  static const String salesmanVendors = 'salesmen/me/vendors';
  static const String salesmanCommissions = 'salesmen/me/commissions';
  static const String vendorMe = 'vendors/me';
  static const String vendorMeServices = 'vendors/me/services';
  static const String vendorMePortfolio = 'vendors/me/portfolio';
  static const String customerMeLocation = 'customers/me/location';
  static const String vendorSearch = 'vendors/search';
  static const String leads = 'leads';
  static const String reviews = 'reviews';
  static const String vendorMeLeads = 'vendors/me/leads';
  static const String vendorMeReviews = 'vendors/me/reviews';
  static const String customerMeFavorites = 'customers/me/favorites';
  static const String customerMeReviews = 'customers/me/reviews';
  static const String customerMeAddresses = 'customers/me/addresses';

  late final Dio _dio = Dio(
    BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 30),
      receiveTimeout: const Duration(seconds: 30),
      headers: {
        HttpHeaders.acceptHeader: 'application/json',
        HttpHeaders.contentTypeHeader: 'application/json',
      },
      // The envelope is meaningful on 4xx too, so let those through to be
      // parsed rather than thrown.
      validateStatus: (status) => status != null && status < 500,
    ),
  )..interceptors.addAll([
      InterceptorsWrapper(
        onRequest: (options, handler) {
          // The server returns category, plan and banner names in this
          // language, falling back to English where none is entered.
          options.headers[HttpHeaders.acceptLanguageHeader] = Injector.language;

          if (Injector.accessToken.isNotEmpty) {
            options.headers[HttpHeaders.authorizationHeader] =
                'Bearer ${Injector.accessToken}';
          }
          return handler.next(options);
        },
      ),
      // Same one-line-per-part format as the ehhs provider app, so logs read
      // the same across both projects. Added after the auth interceptor so
      // the headers printed are the ones actually sent.
      if (kDebugMode)
        InterceptorsWrapper(
          onRequest: (options, handler) {
            _log('⏳ REQUEST → ${options.method} ${options.uri}');
            _log('📌 HEADERS → ${_redacted(options.headers)}');
            _log('📌 BODY → ${options.data is FormData ? _formDataSummary(options.data as FormData) : options.data} : ${options.queryParameters}');
            return handler.next(options);
          },
          onResponse: (response, handler) {
            // 4xx arrive here too (see validateStatus), not in onError.
            _log('✅ RESPONSE → ${response.statusCode} ${response.requestOptions.method} ${response.requestOptions.uri}');
            _log('📦 DATA → ${response.data}');
            return handler.next(response);
          },
          onError: (e, handler) {
            _log('❌ ERROR → ${e.requestOptions.method} ${e.requestOptions.uri} '
                '${e.response?.statusCode ?? ''} ${e.message}');
            if (e.response?.data != null) _log('📦 DATA → ${e.response?.data}');
            return handler.next(e);
          },
        ),
    ]);

  /// debugPrint with a wrap width: logcat silently cuts a single line off
  /// at ~1 KB, which would truncate most JSON responses.
  static void _log(String message) => debugPrint(message, wrapWidth: 1024);

  /// Bearer token shortened to its tail — enough to tell sessions apart in a
  /// log without leaving a usable credential in it.
  static Map<String, dynamic> _redacted(Map<String, dynamic> headers) {
    final auth = headers[HttpHeaders.authorizationHeader];
    if (auth is! String || auth.length <= 12) return headers;
    return {
      ...headers,
      HttpHeaders.authorizationHeader: 'Bearer …${auth.substring(auth.length - 6)}',
    };
  }

  /// FormData's own toString is just "Instance of 'FormData'".
  static String _formDataSummary(FormData form) => {
        for (final field in form.fields) field.key: field.value,
        for (final file in form.files) file.key: '<file ${file.value.filename ?? ''} ${file.value.length}B>',
      }.toString();

  // ── Auth ─────────────────────────────────────────────────────────────────

  Future<CommonResponse?> registerAPI({required Map<String, dynamic> body}) =>
      _post(register, body);

  Future<CommonResponse?> loginAPI({required Map<String, dynamic> body}) =>
      _post(login, body);

  Future<CommonResponse?> logoutAPI() => _post(logout, {});

  /// Changing your own password while signed in — the only password route
  /// there is. There is no emailed reset: the platform sends no mail, and a
  /// forgotten password is replaced by an admin issuing a new temporary one
  /// from the panel, which lands the user here on next sign-in.
  Future<CommonResponse?> changePasswordAPI({required Map<String, dynamic> body}) =>
      _post(changePassword, body);

  /// "Forgot password?" — asks an admin to issue a temporary password. No
  /// token: the caller cannot sign in. The server answers the same whether
  /// or not the email has an account, so the app always shows one message.
  Future<CommonResponse?> passwordResetRequestAPI({required Map<String, dynamic> body}) =>
      _post(passwordResetRequests, body);

  /// The signed-in user's own profile. Also how the account-verification
  /// screen finds out an admin has made a decision — it is one of the few
  /// routes an unapproved account may still call.
  Future<CommonResponse?> userAPI() => _get(user);

  // ── Vendors (salesman add-vendor flow, SPEC 2.2) ─────────────────────────

  /// Creates the vendor in Draft before payment, so a dropped connection
  /// resumes rather than restarting. Resubmitting the same details returns
  /// the existing draft with `resumed: true` instead of a duplicate error.
  Future<CommonResponse?> vendorDraftAPI({required Map<String, dynamic> body}) =>
      _post(vendorDraft, body);

  Future<CommonResponse?> vendorShowAPI({required int vendorId}) =>
      _get('$vendors/$vendorId');

  /// Re-sends the vendor's "set your password" email (Firebase sign-in on
  /// the server). Answers with `password_link_sent_to`, the masked address.
  Future<CommonResponse?> sendVendorPasswordLinkAPI({required int vendorId}) =>
      _post('$vendors/$vendorId/password-link', {});

  /// The vendor's own record (SPEC section 3.2) — checked on login to
  /// decide dashboard vs plan-selection, per has_active_subscription.
  Future<CommonResponse?> vendorMeAPI() => _get(vendorMe);

  /// The vendor editing their own business profile (SPEC section 3.2).
  /// No id in the body — the server resolves the vendor from the token.
  Future<CommonResponse?> updateVendorMeAPI({required Map<String, dynamic> body}) =>
      _patch(vendorMe, body);

  /// Adding categories/subcategories/zones to the caller's own active
  /// subscription, within whatever quota is still unused (SPEC section
  /// 3.3, task 4.4). Unlike subscribeAPI, no Idempotency-Key: this has no
  /// payment/commission side effect, and the server-side diff-based insert
  /// is naturally idempotent.
  Future<CommonResponse?> addServicesAPI({required Map<String, dynamic> body}) =>
      _post(vendorMeServices, body);

  /// The vendor's own portfolio uploads plus current photo/video quota
  /// (SPEC section 3 item 5, task 4.5) — the read companion to
  /// uploadPortfolioMediaAPI below.
  Future<CommonResponse?> vendorPortfolioAPI() => _get(vendorMePortfolio);

  /// Uploads one portfolio photo or video within remaining quota. No
  /// Idempotency-Key, matching addServicesAPI's reasoning — no payment
  /// side effect here either.
  Future<CommonResponse?> uploadPortfolioMediaAPI({
    required String type,
    required int subcategoryId,
    required String filePath,
    ProgressCallback? onSendProgress,
  }) async {
    final form = FormData.fromMap({
      'type': type,
      'subcategory_id': subcategoryId,
      'file': await MultipartFile.fromFile(filePath),
    });

    return _multipart(vendorMePortfolio, form, onSendProgress: onSendProgress);
  }

  /// The vendor Leads tab (SPEC section 3 item 7, task 4.8) — every
  /// customer who tapped Call or WhatsApp, paginated.
  Future<CommonResponse?> vendorLeadsAPI({int page = 1, int perPage = 15}) =>
      _get(vendorMeLeads, queryParameters: {'page': page, 'per_page': perPage});

  /// SPEC section 3 item 8: "send a review request — scoped to a
  /// specific lead only." Rejects (422) if already requested, the lead
  /// already has a review, or the lead is more than 30 days old.
  Future<CommonResponse?> requestReviewAPI({required int leadId}) =>
      _post('$vendorMeLeads/$leadId/request-review', {});

  /// The vendor's OWN Reviews tab (SPEC section 3 item 8, task 4.8) —
  /// unfiltered, unlike the customer-facing vendor detail response: a
  /// vendor sees a review an admin hid, not have it silently vanish.
  Future<CommonResponse?> vendorReviewsAPI({int page = 1, int perPage = 15}) =>
      _get(vendorMeReviews, queryParameters: {'page': page, 'per_page': perPage});

  /// A vendor's right of reply on a review of their own listing (SPEC
  /// section 4 item 9, task 5.5).
  Future<CommonResponse?> replyToReviewAPI({required int reviewId, required String reply}) =>
      _post('$vendorMeReviews/$reviewId/reply', {'reply': reply});

  // ── Customer (home screen location detection, SPEC 4.2, task 4.6) ────────

  /// Resolves and persists the customer's location in one call — a GPS
  /// point, a pincode, or both (the point wins server-side when both are
  /// present). Not required together: pass whichever the caller has.
  // ── Saved addresses (customer address book) ──────────────────────────────

  /// The whole address book, default first. Unpaginated — capped at 20
  /// server-side, and the picker needs every entry at once.
  Future<CommonResponse?> customerAddressesAPI() => _get(customerMeAddresses);

  /// Body: label (home|office|other), other_label, apartment_number,
  /// building_name, street_address, area, city, state, pincode, latitude,
  /// longitude, is_default. The first address saved is always the default.
  Future<CommonResponse?> addCustomerAddressAPI({required Map<String, dynamic> body}) =>
      _post(customerMeAddresses, body);

  /// Same body as add — the edit form sends every field again.
  Future<CommonResponse?> updateCustomerAddressAPI({
    required int addressId,
    required Map<String, dynamic> body,
  }) =>
      _patch('$customerMeAddresses/$addressId', body);

  Future<CommonResponse?> deleteCustomerAddressAPI({required int addressId}) =>
      _delete('$customerMeAddresses/$addressId');

  /// Marks the address the customer just chose as their default. The
  /// caller still sends its point to updateCustomerLocationAPI — that is
  /// what vendor matching reads.
  Future<CommonResponse?> setDefaultCustomerAddressAPI({required int addressId}) =>
      _post('$customerMeAddresses/$addressId/default', {});

  Future<CommonResponse?> updateCustomerLocationAPI({
    double? latitude,
    double? longitude,
    String? pincode,
  }) =>
      _post(customerMeLocation, {
        'latitude': ?latitude,
        'longitude': ?longitude,
        'pincode': ?pincode,
      });

  // ── Vendor search / detail / leads (SPEC 4 items 4/6/7, tasks 5.3-5.4) ───

  /// The core customer vendor-matching query. Location is always explicit
  /// — a point or a pincode, never resolved from a stored profile (task
  /// 5.3's decision) — since this isn't a "my own record" endpoint.
  Future<CommonResponse?> vendorSearchAPI({
    required int subcategoryId,
    double? latitude,
    double? longitude,
    String? pincode,
    int page = 1,
    int perPage = 15,
    /// 'nearest' | 'rating' | 'new' — the list screen's sort chips.
    /// Omitted (null) keeps the server's own default (plan priority,
    /// then rating, then recency), same behaviour as before these chips
    /// existed — callers that do not care about sort (the home screen's
    /// "Vendors near you" rail) are unaffected.
    String? sort,
  }) =>
      _get(vendorSearch, queryParameters: {
        'subcategory_id': subcategoryId,
        'latitude': ?latitude,
        'longitude': ?longitude,
        'pincode': ?pincode,
        'page': page,
        'per_page': perPage,
        'sort': ?sort,
      });

  /// `latitude`/`longitude` are optional — the response simply omits
  /// distance when absent, unlike search's either/or requirement.
  Future<CommonResponse?> vendorDetailAPI({
    required int vendorId,
    double? latitude,
    double? longitude,
  }) =>
      _get('$vendors/$vendorId/detail', queryParameters: {
        'latitude': ?latitude,
        'longitude': ?longitude,
      });

  /// SPEC section 4 item 7: written BEFORE the dialer/WhatsApp intent
  /// opens, never fire-and-forget. Callers must await this and check
  /// `isSuccess` before launching anything.
  /// The approximate call length for a Call lead, reported when the
  /// customer comes back from the dialer. Best-effort: a failure loses only
  /// the duration, never the lead.
  Future<CommonResponse?> recordLeadDurationAPI({required int leadId, required int durationSeconds}) =>
      _post('$leads/$leadId/duration', {'duration_seconds': durationSeconds});

  /// Profile → My calls.
  Future<CommonResponse?> customerCallsAPI({int page = 1, int perPage = 20}) =>
      _get('customers/me/calls', queryParameters: {'page': page, 'per_page': perPage});

  /// Profile → My reviews: the customer's own reviews, newest first.
  Future<CommonResponse?> customerReviewsAPI({int page = 1, int perPage = 20}) =>
      _get(customerMeReviews, queryParameters: {'page': page, 'per_page': perPage});

  /// Counts for the vendor home "Recent calls" card.
  Future<CommonResponse?> vendorLeadsSummaryAPI() => _get('$vendorMeLeads/summary');

  Future<CommonResponse?> createLeadAPI({
    required int vendorId,
    required int subcategoryId,
    int? zoneId,
    required String channel,
  }) =>
      _post(leads, {
        'vendor_id': vendorId,
        'subcategory_id': subcategoryId,
        'zone_id': ?zoneId,
        'channel': channel,
      });

  /// SPEC section 9: gated server-side on a matching lead within the
  /// last 30 days — the server resolves which lead, this call only
  /// needs `vendorId`. A 422 with a `vendor_id` field error means no
  /// eligible lead was found.
  Future<CommonResponse?> submitReviewAPI({
    required int vendorId,
    required int rating,
    String? comment,
  }) =>
      _post(reviews, {
        'vendor_id': vendorId,
        'rating': rating,
        'comment': ?comment,
      });

  // ── Favorites / share / report vendor / account deletion (SPEC 4 item 10)

  /// Toggles — creates if absent, deletes if present — rather than
  /// separate favorite/unfavorite calls, since the caller only ever
  /// needs "is it favorited now" either way.
  Future<CommonResponse?> toggleFavoriteAPI({required int vendorId}) =>
      _post('$vendors/$vendorId/favorite', {});

  Future<CommonResponse?> favoritesAPI({int page = 1, int perPage = 15}) =>
      _get(customerMeFavorites, queryParameters: {'page': page, 'per_page': perPage});

  Future<CommonResponse?> reportVendorAPI({required int vendorId, required String reason}) =>
      _post('$vendors/$vendorId/report', {'reason': reason});

  /// Self-service account deletion (SPEC section 4 item 10, "required
  /// for app store compliance") — reuses the same tombstone mechanism
  /// the admin panel already uses, exposed here for the caller's own
  /// account only. `password` re-verifies the caller, same reasoning as
  /// changePasswordAPI's `current_password`.
  Future<CommonResponse?> deleteAccountAPI({required String password}) =>
      _delete(user, body: {'password': password});

  // ── Master data (SPEC 2.2 step 2: plan → categories/subcategories → zones)

  /// Unpaginated by design (CLAUDE.md) — the whole tree is needed in one
  /// request to render selection with a live "X of Y selected" counter.
  Future<CommonResponse?> categoriesAPI() => _get(categories);

  /// "X vendors" per subcategory on the subcategories screen. Location is
  /// optional here, unlike vendorSearchAPI — the screen opens before the
  /// customer's location has necessarily resolved (SPEC section 4.2's own
  /// fallback states), and the server just returns every count as zero
  /// rather than rejecting the request.
  Future<CommonResponse?> categoryVendorCountsAPI({
    required int categoryId,
    double? latitude,
    double? longitude,
    String? pincode,
  }) =>
      _get('$categories/$categoryId/vendor-counts', queryParameters: {
        'latitude': ?latitude,
        'longitude': ?longitude,
        'pincode': ?pincode,
      });

  /// Free-text service lookup for the customer search bar. A short
  /// ranked slice, unlike categoriesAPI's whole-tree master data.
  Future<CommonResponse?> searchServicesAPI({required String query}) =>
      _get(serviceSearch, queryParameters: {'q': query});

  Future<CommonResponse?> plansAPI() => _get(plans);

  Future<CommonResponse?> zonesAPI() => _get(zones);

  /// A whitelisted subset of admin Settings (SPEC section 5.17) — currently
  /// just free_trial_max_days. Fetched live rather than hardcoded, so an
  /// admin-changed cap takes effect without an app update.
  Future<CommonResponse?> settingsAPI() => _get(settings);

  // ── Salesman (My Vendors / Earnings, SPEC 2.3, 2.4) ──────────────────────

  /// The salesman's own profile plus headline stats (SPEC section 2.5).
  Future<CommonResponse?> salesmanMeAPI() => _get(salesmanMe);

  /// Name and phone only — everything else on the salesman record is
  /// admin-owned, and the server rejects the rest regardless.
  Future<CommonResponse?> updateSalesmanProfileAPI({required Map<String, dynamic> body}) =>
      _patch(salesmanMe, body);

  /// Language and the notification mute, shared by all three apps.
  Future<CommonResponse?> updatePreferencesAPI({required Map<String, dynamic> body}) =>
      _patch(userPreferences, body);

  /// Name, email and phone on any app's profile; each answers with the
  /// updated user. A changed email needs `current_password` too.
  Future<CommonResponse?> updateProfileAPI({required Map<String, dynamic> body}) =>
      _patch(userProfile, body);

  /// Sets the profile photo (multipart field `photo`, an image).
  Future<CommonResponse?> uploadAvatarAPI({required String filePath}) async {
    final form = FormData.fromMap({'photo': await MultipartFile.fromFile(filePath)});

    return _multipart(userAvatar, form);
  }

  Future<CommonResponse?> deleteAvatarAPI() => _delete(userAvatar);

  /// Signs a private-channel subscription for the live socket
  /// (SocketService). Laravel answers this one outside the usual envelope
  /// — a bare `{"auth": "key:signature"}` — so it returns that string, or
  /// null when refused or unreachable.
  Future<String?> broadcastingAuthAPI({
    required String socketId,
    required String channelName,
  }) async {
    try {
      final response = await _dio.post(
        broadcastingAuth,
        data: {'socket_id': socketId, 'channel_name': channelName},
      );
      final data = response.data;

      if (response.statusCode == 200 && data is Map && data['auth'] is String) {
        return data['auth'] as String;
      }
    } catch (e) {
      if (kDebugMode) {
        print('POST $broadcastingAuth failed: $e');
      }
    }

    return null;
  }

  Future<CommonResponse?> salesmanVendorsAPI() => _get(salesmanVendors);

  Future<CommonResponse?> salesmanCommissionsAPI() => _get(salesmanCommissions);

  /// Subscribe (SPEC section 6). The Idempotency-Key header is required
  /// server-side and enforced via a unique column there — a dropped
  /// response and a retry with the same key returns the original
  /// subscription rather than creating a second one.
  /// Renewal for a vendor who already had a plan — active (early), grace
  /// or expired. Same body as subscribeAPI minus free_trial_days, and the
  /// same Idempotency-Key handling. No temporary password comes back.
  Future<CommonResponse?> renewSubscriptionAPI({
    required Map<String, dynamic> body,
    required String idempotencyKey,
  }) =>
      _post('$subscriptions/renew', body, headers: {'Idempotency-Key': idempotencyKey});

  Future<CommonResponse?> subscribeAPI({
    required Map<String, dynamic> body,
    required String idempotencyKey,
  }) =>
      _post(subscriptions, body, headers: {'Idempotency-Key': idempotencyKey});

  /// Shop photo upload — the public profile picture customers see. A
  /// separate call from the draft create on purpose: a slow or failed photo
  /// on a weak connection costs the upload, not the typed business details.
  ///
  /// Identity documents no longer go here: they are per-type KYC documents
  /// (uploadVendorDocumentAPI), each verified by admin on its own.
  Future<CommonResponse?> vendorKycAPI({
    required int vendorId,
    required String shopPhotoPath,
  }) async {
    final form = FormData.fromMap({
      'shop_photo': await MultipartFile.fromFile(shopPhotoPath),
    });

    return _multipart('$vendors/$vendorId/kyc', form);
  }

  // ── Vendor KYC documents (admin-defined types, one document per type) ────

  /// Every document type with the vendor's document for it, plus the
  /// compulsory types not yet Verified. [vendorId] null is the signed-in
  /// vendor's own (`vendors/me/documents`, reachable while pending
  /// approval); otherwise a vendor the signed-in salesman onboarded.
  Future<CommonResponse?> vendorDocumentsAPI({int? vendorId}) =>
      _get(_vendorDocumentsPath(vendorId));

  /// Uploads (or re-uploads) one document. [fields] carries
  /// `document_type_id` and, when the type asks for them, `number` and
  /// `expiry_date`; [frontPath] is required, [backPath] only for two-sided
  /// types. Answers with the same full list as vendorDocumentsAPI.
  Future<CommonResponse?> uploadVendorDocumentAPI({
    int? vendorId,
    required Map<String, dynamic> fields,
    required String frontPath,
    String? backPath,
  }) async {
    final form = FormData.fromMap({
      ...fields,
      'front': await MultipartFile.fromFile(frontPath),
      if (backPath != null) 'back': await MultipartFile.fromFile(backPath),
    });

    return _multipart(_vendorDocumentsPath(vendorId), form);
  }

  static String _vendorDocumentsPath(int? vendorId) =>
      vendorId == null ? '$vendorMe/documents' : '$vendors/$vendorId/documents';

  // ── Plumbing ─────────────────────────────────────────────────────────────

  Future<CommonResponse?> _post(
    String path,
    Map<String, dynamic> body, {
    Map<String, String>? headers,
  }) async {
    try {
      final response = await _dio.post(
        path,
        data: body,
        options: headers == null ? null : Options(headers: headers),
      );
      return _parse(response);
    } catch (e) {
      if (kDebugMode) {
        print('POST $path failed: $e');
      }
      return null;
    }
  }

  /// Multipart variant of _post. Dio sets the boundary and content-type
  /// itself for FormData, so the JSON content-type header from BaseOptions is
  /// overridden per request rather than globally.
  ///
  /// [onSendProgress] reports bytes written as they go out, so a caller
  /// uploading a video — tens of megabytes over a phone connection — can
  /// show real progress instead of an indeterminate spinner.
  Future<CommonResponse?> _multipart(
    String path,
    FormData form, {
    ProgressCallback? onSendProgress,
  }) async {
    try {
      final response = await _dio.post(
        path,
        data: form,
        options: Options(contentType: 'multipart/form-data'),
        onSendProgress: onSendProgress,
      );
      return _parse(response);
    } catch (e) {
      if (kDebugMode) {
        print('MULTIPART $path failed: $e');
      }
      return null;
    }
  }

  Future<CommonResponse?> _delete(String path, {Map<String, dynamic>? body}) async {
    try {
      final response = await _dio.delete(path, data: body);
      return _parse(response);
    } catch (e) {
      if (kDebugMode) {
        print('DELETE $path failed: $e');
      }
      return null;
    }
  }

  Future<CommonResponse?> _patch(String path, Map<String, dynamic> body) async {
    try {
      final response = await _dio.patch(path, data: body);
      return _parse(response);
    } catch (e) {
      if (kDebugMode) {
        print('PATCH $path failed: $e');
      }
      return null;
    }
  }

  Future<CommonResponse?> _get(String path, {Map<String, dynamic>? queryParameters}) async {
    try {
      final response = await _dio.get(path, queryParameters: queryParameters);
      return _parse(response);
    } catch (e) {
      if (kDebugMode) {
        print('GET $path failed: $e');
      }
      return null;
    }
  }

  CommonResponse? _parse(Response<dynamic> response) {
    final data = response.data;
    if (data is! Map<String, dynamic>) {
      return null;
    }

    final parsed = CommonResponse.fromJson(data, statusCode: response.statusCode);

    // The token is gone server-side, so drop the local copy rather than
    // letting screens retry with a credential that can never work again.
    if (response.statusCode == 401) {
      Injector.clearUserData();
    }

    return parsed;
  }
}
