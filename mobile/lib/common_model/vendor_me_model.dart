/// The vendor's own record (`GET /api/vendors/me`, SPEC section 3.2) —
/// what login checks to decide dashboard vs plan-selection.
class VendorMeModel {
  int? id;
  String? businessName;
  String? ownerName;
  String? phone;
  String? email;
  String? avatarUrl;
  String? address;
  String? city;
  String? about;
  String? status;
  bool hasActiveSubscription;
  ActiveSubscriptionModel? activeSubscription;

  /// A sale waiting on its Razorpay payment link — routes the vendor to the
  /// "Waiting for payment" screen instead of plan selection.
  PendingPaymentModel? pendingPayment;

  VendorMeModel({
    this.id,
    this.businessName,
    this.ownerName,
    this.phone,
    this.email,
    this.address,
    this.city,
    this.about,
    this.status,
    this.hasActiveSubscription = false,
    this.activeSubscription,
  });

  VendorMeModel.fromJson(Map<String, dynamic> json)
      : id = (json['vendor'] as Map<String, dynamic>?)?['id'] as int?,
        businessName = (json['vendor'] as Map<String, dynamic>?)?['business_name'] as String?,
        ownerName = (json['vendor'] as Map<String, dynamic>?)?['owner_name'] as String?,
        phone = (json['vendor'] as Map<String, dynamic>?)?['phone'] as String?,
        email = (json['vendor'] as Map<String, dynamic>?)?['email'] as String?,
        avatarUrl = (json['vendor'] as Map<String, dynamic>?)?['avatar_url'] as String?,
        address = (json['vendor'] as Map<String, dynamic>?)?['address'] as String?,
        city = (json['vendor'] as Map<String, dynamic>?)?['city'] as String?,
        about = (json['vendor'] as Map<String, dynamic>?)?['about'] as String?,
        status = (json['vendor'] as Map<String, dynamic>?)?['status'] as String?,
        hasActiveSubscription =
            (json['vendor'] as Map<String, dynamic>?)?['has_active_subscription'] as bool? ?? false,
        activeSubscription = json['active_subscription'] is Map<String, dynamic>
            ? ActiveSubscriptionModel.fromJson(json['active_subscription'] as Map<String, dynamic>)
            : null,
        pendingPayment = (json['vendor'] as Map<String, dynamic>?)?['pending_payment'] is Map<String, dynamic>
            ? PendingPaymentModel.fromJson(
                (json['vendor'] as Map<String, dynamic>)['pending_payment'] as Map<String, dynamic>,
              )
            : null;
}

/// `vendor.pending_payment` — the plan and amount a sent payment link is for.
class PendingPaymentModel {
  String? planName;
  int? amountPaise;

  /// ISO 8601.
  String? expiresAt;

  PendingPaymentModel({this.planName, this.amountPaise, this.expiresAt});

  PendingPaymentModel.fromJson(Map<String, dynamic> json)
      : planName = json['plan_name'] as String?,
        amountPaise = json['amount_paise'] as int?,
        expiresAt = json['expires_at'] as String?;
}

/// Nested in `VendorMeModel` — plan name, quota used/total per resource,
/// days remaining, the actual selected items per resource (task 4.4), and
/// photo/video quota (task 4.5 — used/max only; the actual portfolio list
/// comes from `GET /vendors/me/portfolio`, not this endpoint, since it can
/// grow large and this payload is fetched on every dashboard entry). No
/// leads or rating yet — Phase 5's lead-matching doesn't exist.
class ActiveSubscriptionModel {
  String? planName;
  String? endDate;
  int? daysRemaining;
  QuotaResourceModel? categories;
  QuotaResourceModel? subcategories;
  QuotaResourceModel? zones;
  QuotaResourceModel? photos;
  QuotaResourceModel? videos;
  List<SelectedServiceItemModel> selectedCategories;
  List<SelectedServiceItemModel> selectedSubcategories;
  List<SelectedServiceItemModel> selectedZones;

  ActiveSubscriptionModel({
    this.planName,
    this.endDate,
    this.daysRemaining,
    this.categories,
    this.subcategories,
    this.zones,
    this.photos,
    this.videos,
    List<SelectedServiceItemModel>? selectedCategories,
    List<SelectedServiceItemModel>? selectedSubcategories,
    List<SelectedServiceItemModel>? selectedZones,
  })  : selectedCategories = selectedCategories ?? [],
        selectedSubcategories = selectedSubcategories ?? [],
        selectedZones = selectedZones ?? [];

  ActiveSubscriptionModel.fromJson(Map<String, dynamic> json)
      : planName = json['plan_name'] as String?,
        endDate = json['end_date'] as String?,
        daysRemaining = json['days_remaining'] as int?,
        categories = _quotaFrom(json, 'categories'),
        subcategories = _quotaFrom(json, 'subcategories'),
        zones = _quotaFrom(json, 'zones'),
        photos = _quotaFrom(json, 'photos'),
        videos = _quotaFrom(json, 'videos'),
        selectedCategories = _itemsFrom(json, 'categories'),
        selectedSubcategories = _itemsFrom(json, 'subcategories'),
        selectedZones = _itemsFrom(json, 'zones');

  static QuotaResourceModel? _quotaFrom(Map<String, dynamic> json, String key) {
    final quota = json['quota'] as Map<String, dynamic>?;
    final resource = quota?[key];
    return resource is Map<String, dynamic> ? QuotaResourceModel.fromJson(resource) : null;
  }

  static List<SelectedServiceItemModel> _itemsFrom(Map<String, dynamic> json, String key) {
    final items = json['items'] as Map<String, dynamic>?;
    final list = items?[key];
    return list is List
        ? list
            .map((e) => SelectedServiceItemModel.fromJson(e as Map<String, dynamic>))
            .toList()
        : [];
  }
}

class QuotaResourceModel {
  int? used;
  int? max;

  QuotaResourceModel({this.used, this.max});

  QuotaResourceModel.fromJson(Map<String, dynamic> json)
      : used = json['used'] as int?,
        max = json['max'] as int?;
}

/// One selected category/subcategory/zone (task 4.4) — the same {id, name}
/// shape across all three resources, so one class covers them all.
class SelectedServiceItemModel {
  int? id;
  String? name;

  /// Set on subcategories only, so the Services screen can list them under
  /// the category they belong to. Null for categories and zones, which
  /// have no parent.
  int? categoryId;

  SelectedServiceItemModel({this.id, this.name, this.categoryId});

  SelectedServiceItemModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        name = json['name'] as String?,
        categoryId = json['category_id'] as int?;
}
