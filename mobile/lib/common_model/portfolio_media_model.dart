import 'vendor_me_model.dart';

/// One portfolio photo/video (`GET`/`POST /api/vendors/me/portfolio`,
/// SPEC section 3 item 5, task 4.5).
class PortfolioMediaModel {
  int? id;
  String? type; // 'image' | 'video'
  String? url;
  int? subcategoryId;
  String? subcategoryName;
  String? moderationStatus; // 'pending' | 'approved' | 'rejected'
  String? rejectionReason;
  String? createdAt;

  PortfolioMediaModel({
    this.id,
    this.type,
    this.url,
    this.subcategoryId,
    this.subcategoryName,
    this.moderationStatus,
    this.rejectionReason,
    this.createdAt,
  });

  PortfolioMediaModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        type = json['type'] as String?,
        url = json['url'] as String?,
        subcategoryId = json['subcategory_id'] as int?,
        subcategoryName = json['subcategory_name'] as String?,
        moderationStatus = json['moderation_status'] as String?,
        rejectionReason = json['rejection_reason'] as String?,
        createdAt = json['created_at'] as String?;
}

/// `GET /api/vendors/me/portfolio`'s full envelope — the vendor's own
/// uploads plus current photo/video quota.
class VendorPortfolioModel {
  List<PortfolioMediaModel> media;
  QuotaResourceModel? photos;
  QuotaResourceModel? videos;

  VendorPortfolioModel({
    List<PortfolioMediaModel>? media,
    this.photos,
    this.videos,
  }) : media = media ?? [];

  VendorPortfolioModel.fromJson(Map<String, dynamic> json)
      : media = ((json['media'] as List<dynamic>?) ?? [])
            .map((e) => PortfolioMediaModel.fromJson(e as Map<String, dynamic>))
            .toList(),
        photos = _quotaFrom(json, 'photos'),
        videos = _quotaFrom(json, 'videos');

  static QuotaResourceModel? _quotaFrom(Map<String, dynamic> json, String key) {
    final quota = json['quota'] as Map<String, dynamic>?;
    final resource = quota?[key];
    return resource is Map<String, dynamic> ? QuotaResourceModel.fromJson(resource) : null;
  }
}
