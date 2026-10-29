/// One review on the vendor's OWN Reviews tab (SPEC section 3 item 8,
/// task 4.8) — unlike the customer-facing `ReviewModel`, this carries
/// `isHidden`, since a vendor should see every review on their own
/// listing, hidden or not, not have one silently disappear.
class VendorReviewModel {
  int? id;
  int? rating;
  String? comment;
  String? customerName;
  String? vendorReply;
  String? repliedAt;
  bool isHidden;
  String? createdAt;

  VendorReviewModel({
    this.id,
    this.rating,
    this.comment,
    this.customerName,
    this.vendorReply,
    this.repliedAt,
    this.isHidden = false,
    this.createdAt,
  });

  VendorReviewModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        rating = json['rating'] as int?,
        comment = json['comment'] as String?,
        customerName = json['customer_name'] as String?,
        vendorReply = json['vendor_reply'] as String?,
        repliedAt = json['replied_at'] as String?,
        isHidden = json['is_hidden'] as bool? ?? false,
        createdAt = json['created_at'] as String?;
}
