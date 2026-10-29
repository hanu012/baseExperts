/// The resolved zone from `POST /api/customers/me/location` (SPEC section
/// 4.2, task 4.6) — `null` when GPS/pincode matched no defined zone.
class ResolvedZoneModel {
  int? id;
  String? name;

  ResolvedZoneModel({this.id, this.name});

  ResolvedZoneModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        name = json['name'] as String?;
}
