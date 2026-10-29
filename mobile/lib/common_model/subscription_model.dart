/// A subscription (`POST /api/subscriptions`, SPEC section 6) — just enough
/// for the confirmation screen to show plan/price without re-fetching
/// anything the subscribe response already returned.
class SubscriptionModel {
  int? id;
  int? vendorId;
  int? planId;
  String? planName;
  String? source;
  String? status;
  String? startDate;
  String? endDate;
  int? pricePaise;

  /// The payment's status — "pending" while a Razorpay payment link is
  /// unpaid (only when Razorpay is switched on in admin Settings).
  String? paymentStatus;
  String? paymentLinkUrl;
  String? paymentLinkExpiresAt;

  /// The sale is waiting on the vendor paying the link; nothing is active.
  bool get isAwaitingPayment => status == 'pending_payment' || paymentStatus == 'pending';

  SubscriptionModel({
    this.id,
    this.vendorId,
    this.planId,
    this.planName,
    this.source,
    this.status,
    this.startDate,
    this.endDate,
    this.pricePaise,
  });

  SubscriptionModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        vendorId = json['vendor_id'] as int?,
        planId = json['plan_id'] as int?,
        planName = json['plan_name'] as String?,
        source = json['source'] as String?,
        status = json['status'] as String?,
        startDate = json['start_date'] as String?,
        endDate = json['end_date'] as String?,
        pricePaise = json['price_paise'] as int?,
        paymentStatus = (json['payment'] as Map<String, dynamic>?)?['status'] as String?,
        paymentLinkUrl = (json['payment'] as Map<String, dynamic>?)?['payment_link_url'] as String?,
        paymentLinkExpiresAt =
            (json['payment'] as Map<String, dynamic>?)?['payment_link_expires_at'] as String?;
}
