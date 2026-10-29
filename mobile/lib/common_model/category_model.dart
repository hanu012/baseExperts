/// A category with its active subcategories nested (`GET /api/categories`).
///
/// `subcategories` is always present (possibly empty) — the backend only
/// ever eager-loads it, never omits it.
class CategoryModel {
  int? id;
  String? name;
  String? slug;
  String? iconUrl;
  int? sortOrder;
  List<SubcategoryModel> subcategories;

  CategoryModel({
    this.id,
    this.name,
    this.slug,
    this.iconUrl,
    this.sortOrder,
    List<SubcategoryModel>? subcategories,
  }) : subcategories = subcategories ?? [];

  CategoryModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        name = json['name'] as String?,
        slug = json['slug'] as String?,
        iconUrl = json['icon_url'] as String?,
        sortOrder = json['sort_order'] as int?,
        subcategories = ((json['subcategories'] as List<dynamic>?) ?? [])
            .map((e) => SubcategoryModel.fromJson(e as Map<String, dynamic>))
            .toList();
}

class SubcategoryModel {
  int? id;
  int? categoryId;
  String? name;
  String? slug;
  String? iconUrl;
  int? sortOrder;

  /// 'installation' | 'repair' | 'maintenance', or null when the admin
  /// has not classified this subcategory — backs the filter chips on the
  /// customer subcategories screen.
  String? serviceType;

  /// Shows a "Popular" badge and surfaces this service first.
  bool isPopular;

  SubcategoryModel({
    this.id,
    this.categoryId,
    this.name,
    this.slug,
    this.iconUrl,
    this.sortOrder,
    this.serviceType,
    this.isPopular = false,
  });

  SubcategoryModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        categoryId = json['category_id'] as int?,
        name = json['name'] as String?,
        slug = json['slug'] as String?,
        iconUrl = json['icon_url'] as String?,
        sortOrder = json['sort_order'] as int?,
        serviceType = json['service_type'] as String?,
        isPopular = json['is_popular'] as bool? ?? false;
}
