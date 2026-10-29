/// One saved address in the customer's address book
/// (`GET customers/me/addresses`).
class CustomerAddressModel {
  CustomerAddressModel({
    this.id,
    this.label,
    this.otherLabel,
    this.displayLabel,
    this.apartmentNumber,
    this.buildingName,
    this.streetAddress,
    this.area,
    this.city,
    this.state,
    this.pincode,
    this.latitude,
    this.longitude,
    this.isDefault = false,
  });

  int? id;

  /// home | office | other
  String? label;
  String? otherLabel;

  /// "Home", "Office", or the customer's own name for an "other".
  String? displayLabel;

  String? apartmentNumber;
  String? buildingName;
  String? streetAddress;
  String? area;
  String? city;
  String? state;
  String? pincode;
  double? latitude;
  double? longitude;
  bool isDefault;

  CustomerAddressModel.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int?,
        label = json['label'] as String?,
        otherLabel = json['other_label'] as String?,
        displayLabel = json['display_label'] as String?,
        apartmentNumber = json['apartment_number'] as String?,
        buildingName = json['building_name'] as String?,
        streetAddress = json['street_address'] as String?,
        area = json['area'] as String?,
        city = json['city'] as String?,
        state = json['state'] as String?,
        pincode = json['pincode'] as String?,
        latitude = (json['latitude'] as num?)?.toDouble(),
        longitude = (json['longitude'] as num?)?.toDouble(),
        isDefault = json['is_default'] as bool? ?? false;

  bool get hasPoint => latitude != null && longitude != null;

  /// "B-402, Shivalik Heights, Satellite Rd, Jodhpur, Ahmedabad" — area and
  /// city are left out because the geocoder line usually repeats them.
  String get fullAddress => [apartmentNumber, buildingName, streetAddress]
      .whereType<String>()
      .where((part) => part.trim().isNotEmpty)
      .join(', ');
}
