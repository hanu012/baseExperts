/// A subscription plan with its quota flattened onto it (`GET /api/plans`).
///
/// The quota fields are the UX-only caps this screen enforces client-side —
/// the real enforcer is the server-side subscribe endpoint (SPEC section 6,
/// task 3.4), not this model.
class PlanModel {
  int? id;
  String? name;
  String? slug;
  String? description;
  int? pricePaise;
  String? priceRupees;
  int? durationDays;
  int? sortOrder;
  int? maxCategories;
  int? maxSubcategories;
  int? maxZones;
  int? maxPhotos;
  int? maxVideos;
  int? priorityRank;

  PlanModel({
    this.id,
    this.name,
    this.slug,
    this.description,
    this.pricePaise,
    this.priceRupees,
    this.durationDays,
    this.sortOrder,
    this.maxCategories,
    this.maxSubcategories,
    this.maxZones,
    this.maxPhotos,
    this.maxVideos,
    this.priorityRank,
  });

  PlanModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        name = json['name'] as String?,
        slug = json['slug'] as String?,
        description = json['description'] as String?,
        pricePaise = json['price_paise'] as int?,
        priceRupees = json['price_rupees'] as String?,
        durationDays = json['duration_days'] as int?,
        sortOrder = json['sort_order'] as int?,
        maxCategories = json['max_categories'] as int?,
        maxSubcategories = json['max_subcategories'] as int?,
        maxZones = json['max_zones'] as int?,
        maxPhotos = json['max_photos'] as int?,
        maxVideos = json['max_videos'] as int?,
        priorityRank = json['priority_rank'] as int?;
}
