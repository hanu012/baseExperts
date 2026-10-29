/// A zone (`GET /api/zones`) — a nested tree of parent zones with their
/// active leaf children.
///
/// `isLeaf` decides selectability (SPEC section 8): true for a leaf child
/// AND for a standalone top-level zone with no sub-zones. A parent with
/// children is `isLeaf == false` and is navigation/grouping only, never a
/// coverage unit itself.
class ZoneModel {
  int? id;
  String? name;
  String? pincode;
  bool isLeaf;
  List<ZoneModel> children;

  ZoneModel({
    this.id,
    this.name,
    this.pincode,
    this.isLeaf = true,
    List<ZoneModel>? children,
  }) : children = children ?? [];

  ZoneModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        name = json['name'] as String?,
        pincode = json['pincode'] as String?,
        isLeaf = (json['is_leaf'] as bool?) ?? true,
        children = ((json['children'] as List<dynamic>?) ?? [])
            .map((e) => ZoneModel.fromJson(e as Map<String, dynamic>))
            .toList();
}
