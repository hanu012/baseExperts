/// A vendor row on the salesman's My Vendors tab (`GET /api/salesmen/me/vendors`,
/// SPEC section 2.3).
///
/// `planName`/`daysToExpiry` are both nullable — a vendor still in Draft has
/// no subscription at all. `daysToExpiry` can be negative: the backend
/// deliberately does not filter to active-only, so an expired vendor still
/// shows its last plan with a negative value rather than going blank.
///
/// No leads field — Phase 5's leads table doesn't exist yet, and the backend
/// omits the column entirely rather than sending a fake zero.
class SalesmanVendorModel {
  int? id;
  String? businessName;
  String? ownerName;
  String? status;
  String? planName;
  int? daysToExpiry;
  String? endDate;

  /// Used/max per sellable resource, batched server-side for the whole
  /// list. Null when there is no active subscription — the "Not
  /// subscribed" state.
  VendorQuotaUsageModel? quota;

  SalesmanVendorModel({
    this.id,
    this.businessName,
    this.ownerName,
    this.status,
    this.planName,
    this.daysToExpiry,
    this.endDate,
    this.quota,
  });

  SalesmanVendorModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        businessName = json['business_name'] as String?,
        ownerName = json['owner_name'] as String?,
        status = json['status'] as String?,
        planName = json['plan_name'] as String?,
        daysToExpiry = json['days_to_expiry'] as int?,
        endDate = json['end_date'] as String?,
        quota = json['quota'] is Map<String, dynamic>
            ? VendorQuotaUsageModel.fromJson(json['quota'] as Map<String, dynamic>)
            : null;

  bool get isSubscribed => quota != null;

  /// An onboarding that was started and never finished: the details were
  /// saved but no plan was ever bought.
  ///
  /// Draft is the only half-finished state the salesman flow can leave
  /// behind. Subscribing takes a salesman-sold vendor straight from draft
  /// to active in one transaction (SubscriptionService::subscribe), so
  /// there is no "plan chosen but not paid" row to land on — which is why
  /// resuming always means "go and pick a plan".
  bool get isDraft => status == 'draft';

  /// Two letters for the avatar tile, from the business name.
  String get initials {
    final parts = (businessName ?? '')
        .trim()
        .split(RegExp(r'\s+'))
        .where((p) => p.isNotEmpty)
        .toList();

    if (parts.isEmpty) {
      return '?';
    }
    if (parts.length == 1) {
      final one = parts.first;
      return (one.length >= 2 ? one.substring(0, 2) : one.substring(0, 1)).toUpperCase();
    }

    return '${parts.first[0]}${parts[1][0]}'.toUpperCase();
  }
}

/// The three sellable dimensions. Photos and videos are vendor-supplied
/// content rather than something a salesman sells, so the list endpoint
/// leaves them out — the vendor detail screen shows all five.
class VendorQuotaUsageModel {
  QuotaPairModel? categories;
  QuotaPairModel? subcategories;
  QuotaPairModel? zones;

  VendorQuotaUsageModel({this.categories, this.subcategories, this.zones});

  VendorQuotaUsageModel.fromJson(Map<String, dynamic> json)
      : categories = _pair(json['categories']),
        subcategories = _pair(json['subcategories']),
        zones = _pair(json['zones']);

  static QuotaPairModel? _pair(dynamic value) =>
      value is Map<String, dynamic> ? QuotaPairModel.fromJson(value) : null;

  List<QuotaPairModel> get all =>
      [categories, subcategories, zones].whereType<QuotaPairModel>().toList();
}

class QuotaPairModel {
  int used;
  int max;

  QuotaPairModel({this.used = 0, this.max = 0});

  QuotaPairModel.fromJson(Map<String, dynamic> json)
      : used = json['used'] as int? ?? 0,
        max = json['max'] as int? ?? 0;

  /// 0..1, safe when max is 0 (a plan with no allowance for this resource).
  double get ratio => max == 0 ? 0 : (used / max).clamp(0.0, 1.0);

  bool get isFull => max > 0 && used >= max;
}
