/// One lead on the vendor Leads tab (SPEC section 3 item 7, task 4.8) —
/// a customer who tapped Call or WhatsApp.
class LeadModel {
  int? id;
  String? customerName;
  String? subcategoryName;
  String? zoneName;
  String? channel; // 'call' | 'whatsapp'
  String? createdAt;
  String? reviewRequestedAt;
  bool hasReview;

  /// Approximate call length in seconds (tap Call → back in the customer
  /// app); null for WhatsApp or when not reported.
  int? callDurationSeconds;

  LeadModel({
    this.id,
    this.customerName,
    this.subcategoryName,
    this.zoneName,
    this.channel,
    this.createdAt,
    this.reviewRequestedAt,
    this.hasReview = false,
  });

  LeadModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        customerName = json['customer_name'] as String?,
        subcategoryName = json['subcategory_name'] as String?,
        zoneName = json['zone_name'] as String?,
        channel = json['channel'] as String?,
        createdAt = json['created_at'] as String?,
        reviewRequestedAt = json['review_requested_at'] as String?,
        hasReview = json['has_review'] as bool? ?? false,
        callDurationSeconds = json['call_duration_seconds'] as int?;
}

/// One row of the customer's Profile → My calls (`GET customers/me/calls`).
class CustomerCallModel {
  int? id;
  int? vendorId;
  String? vendorName;
  int? subcategoryId;
  String? subcategoryName;
  String? channel; // 'call' | 'whatsapp'

  /// Approximate (tap Call → back in the app); null for WhatsApp or when
  /// it was not reported.
  int? callDurationSeconds;
  String? createdAt;

  CustomerCallModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        vendorId = json['vendor_id'] as int?,
        vendorName = json['vendor_name'] as String?,
        subcategoryId = json['subcategory_id'] as int?,
        subcategoryName = json['subcategory_name'] as String?,
        channel = json['channel'] as String?,
        callDurationSeconds = json['call_duration_seconds'] as int?,
        createdAt = json['created_at'] as String?;
}

/// Counts for the vendor home "Recent calls" card
/// (`GET vendors/me/leads/summary`).
class LeadsSummaryModel {
  int callsToday;
  int whatsappToday;
  int callsLast7Days;

  /// Approximate, last 30 days; null with nothing to average.
  int? averageCallSeconds;

  LeadsSummaryModel.fromJson(Map<String, dynamic> json)
      : callsToday = json['calls_today'] as int? ?? 0,
        whatsappToday = json['whatsapp_today'] as int? ?? 0,
        callsLast7Days = json['calls_last_7_days'] as int? ?? 0,
        averageCallSeconds = json['average_call_seconds'] as int?;
}
