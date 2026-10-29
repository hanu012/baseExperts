import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:geocoding/geocoding.dart';

/// One place the customer can pick: a label, a fuller address, and —
/// once resolved — a point.
///
/// An autocomplete suggestion arrives without coordinates (Google only
/// returns those from a Details lookup, which is billed separately), so
/// [latitude]/[longitude] stay null until [PlaceService.resolve] is
/// called on the suggestion the customer actually taps. Fetching details
/// for every keystroke's worth of suggestions would multiply the bill by
/// the length of the list for results nobody chose.
class PlaceSuggestion {
  const PlaceSuggestion({
    required this.id,
    required this.title,
    required this.subtitle,
    this.latitude,
    this.longitude,
  });

  /// Google's place_id, or a synthesised id for a locally geocoded hit.
  final String id;

  /// The bold part in the designs — "Maninagar".
  final String title;

  /// The grey line under it — "Ahmedabad, Gujarat, India".
  final String subtitle;

  final double? latitude;
  final double? longitude;

  bool get hasPoint => latitude != null && longitude != null;

  /// What the location screens store and display as one line.
  String get fullAddress =>
      subtitle.isEmpty ? title : '$title, $subtitle';

  PlaceSuggestion withPoint(double lat, double lng) => PlaceSuggestion(
        id: id,
        title: title,
        subtitle: subtitle,
        latitude: lat,
        longitude: lng,
      );
}

/// A reverse-geocoded address split into the parts a saved address stores.
class AddressParts {
  const AddressParts({
    required this.streetAddress,
    this.area,
    this.city,
    this.state,
    this.pincode,
  });

  /// One readable line — "Satellite Rd, Jodhpur, Ahmedabad, Gujarat, 380015".
  final String streetAddress;
  final String? area;
  final String? city;
  final String? state;
  final String? pincode;
}

/// Place search and address lookup for the customer location screens.
///
/// Two providers, deliberately:
///
/// * **Google Places** for typeahead search, because that is what the
///   designs specify ("powered by Google") and what users expect from a
///   location field — partial, misspelled, landmark-aware matching that
///   a plain geocoder does not do.
/// * **The platform geocoder** for turning a point into an address, on
///   the current-location and map screens. It needs no key and no
///   billing, and reverse geocoding is the one thing it does well.
///
/// The Places key comes from `--dart-define=MAPS_API_KEY=...`, the same
/// value `local.properties` gives the Android manifest for map tiles.
/// Without it, [search] falls back to the platform geocoder rather than
/// failing: a cheaper search beats a dead search field.
class PlaceService {
  PlaceService._();

  static final PlaceService instance = PlaceService._();

  static const String _apiKey = String.fromEnvironment('MAPS_API_KEY');

  /// Biases results towards India, matching where the platform operates.
  /// A bias, not a filter — a correct result outside it still ranks.
  static const String _regionBias = 'in';

  /// The platform geocoder. One instance, reused — it holds a channel.
  ///
  /// Built lazily and defensively. Constructing it reaches for a
  /// registered platform implementation, which throws where there is
  /// none — under `flutter test`, and on any platform the plugin does
  /// not support. A null geocoder degrades to coordinates without a
  /// name, which every caller here already handles.
  Geocoding? _geocodingInstance;
  bool _geocodingUnavailable = false;

  Geocoding? get _geocoding {
    if (_geocodingUnavailable) {
      return null;
    }

    try {
      return _geocodingInstance ??= Geocoding();
    } catch (e) {
      if (kDebugMode) {
        print('Geocoding unavailable: $e');
      }
      _geocodingUnavailable = true;
      return null;
    }
  }

  final Dio _dio = Dio(
    BaseOptions(
      baseUrl: 'https://maps.googleapis.com/maps/api/place',
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 10),
    ),
  );

  /// Reused across requests in one typing session so Google bills the
  /// whole session as a single autocomplete rather than per keystroke.
  /// Cleared by [endSession] once a place is chosen.
  String? _sessionToken;

  bool get hasPlacesKey => _apiKey.isNotEmpty;

  /// Starts (or continues) an autocomplete session.
  void _ensureSession() {
    _sessionToken ??= DateTime.now().microsecondsSinceEpoch.toString();
  }

  /// Ends the billing session — call after a suggestion is resolved.
  void endSession() => _sessionToken = null;

  /// Suggestions for what the customer has typed so far.
  ///
  /// Returns empty rather than throwing on any failure: a location field
  /// that shows nothing is recoverable, one that throws is not.
  Future<List<PlaceSuggestion>> search(String query) async {
    final trimmed = query.trim();

    // Two characters is where suggestions start being about the query
    // rather than about the whole country.
    if (trimmed.length < 2) {
      return [];
    }

    if (!hasPlacesKey) {
      return _searchViaGeocoder(trimmed);
    }

    _ensureSession();

    try {
      final response = await _dio.get<Map<String, dynamic>>(
        '/autocomplete/json',
        queryParameters: {
          'input': trimmed,
          'key': _apiKey,
          'sessiontoken': _sessionToken,
          'components': 'country:$_regionBias',
        },
      );

      final body = response.data;
      final status = body?['status'] as String?;

      if (status != 'OK' && status != 'ZERO_RESULTS') {
        if (kDebugMode) {
          print('Places autocomplete status $status ${body?['error_message']}');
        }
        return _searchViaGeocoder(trimmed);
      }

      final predictions = (body?['predictions'] as List<dynamic>?) ?? [];

      return predictions.map((raw) {
        final item = raw as Map<String, dynamic>;
        final formatting = item['structured_formatting'] as Map<String, dynamic>?;

        return PlaceSuggestion(
          id: item['place_id'] as String? ?? '',
          title: formatting?['main_text'] as String? ??
              item['description'] as String? ??
              '',
          subtitle: formatting?['secondary_text'] as String? ?? '',
        );
      }).toList();
    } catch (e) {
      if (kDebugMode) {
        print('Places autocomplete error $e');
      }
      return _searchViaGeocoder(trimmed);
    }
  }

  /// Fills in the coordinates for a chosen suggestion.
  ///
  /// Already-resolved suggestions (the geocoder path supplies points up
  /// front) are returned untouched, so callers can resolve blindly.
  Future<PlaceSuggestion?> resolve(PlaceSuggestion suggestion) async {
    if (suggestion.hasPoint) {
      endSession();
      return suggestion;
    }

    if (!hasPlacesKey) {
      return _resolveViaGeocoder(suggestion);
    }

    try {
      final response = await _dio.get<Map<String, dynamic>>(
        '/details/json',
        queryParameters: {
          'place_id': suggestion.id,
          'key': _apiKey,
          'sessiontoken': _sessionToken,
          // Only what is needed for a point — Details is billed by the
          // field groups requested.
          'fields': 'geometry/location',
        },
      );

      final location = ((response.data?['result'] as Map<String, dynamic>?)?[
          'geometry'] as Map<String, dynamic>?)?['location'] as Map<String, dynamic>?;

      final lat = (location?['lat'] as num?)?.toDouble();
      final lng = (location?['lng'] as num?)?.toDouble();

      if (lat == null || lng == null) {
        return _resolveViaGeocoder(suggestion);
      }

      return suggestion.withPoint(lat, lng);
    } catch (e) {
      if (kDebugMode) {
        print('Place details error $e');
      }
      return _resolveViaGeocoder(suggestion);
    } finally {
      endSession();
    }
  }

  /// The address at a point, for the GPS and map screens.
  ///
  /// Returns null when the platform geocoder has nothing — the screens
  /// then show the coordinates alone, which is still enough to confirm.
  Future<PlaceSuggestion?> addressAt(double latitude, double longitude) async {
    try {
      final marks = await _geocoding?.placemarkFromCoordinates(latitude, longitude);

      if (marks == null) {
        return null;
      }

      if (marks.isEmpty) {
        return null;
      }

      return _fromPlacemark(marks.first, latitude, longitude);
    } catch (e) {
      if (kDebugMode) {
        print('Reverse geocode error $e');
      }
      return null;
    }
  }

  /// The structured address at a point, for the save-address form: the
  /// one-line street address plus the parts stored alongside it. Null when
  /// the geocoder has nothing, as with [addressAt].
  Future<AddressParts?> addressPartsAt(double latitude, double longitude) async {
    try {
      final marks = await _geocoding?.placemarkFromCoordinates(latitude, longitude);

      if (marks == null || marks.isEmpty) {
        return null;
      }

      final mark = marks.first;

      // Plus Codes ("XJQ7+MH5") come back as the name where a point has no
      // street — meaningless to a person, so they are dropped.
      bool isPlusCode(String? value) =>
          value != null && RegExp(r'^[23456789CFGHJMPQRVWX]{4,}\+[23456789CFGHJMPQRVWX]*').hasMatch(value);

      final parts = <String>[];

      // Android often repeats the same value as name and street; keep each
      // once, in order.
      for (final part in [
        mark.name,
        mark.street,
        mark.subLocality,
        mark.locality,
        mark.administrativeArea,
        mark.postalCode,
      ]) {
        final value = part?.trim() ?? '';

        if (value.isEmpty || isPlusCode(value) || parts.contains(value)) {
          continue;
        }

        parts.add(value);
      }

      if (parts.isEmpty) {
        return null;
      }

      return AddressParts(
        streetAddress: parts.join(', '),
        area: _blankToNull(mark.subLocality),
        city: _blankToNull(mark.locality),
        state: _blankToNull(mark.administrativeArea),
        pincode: _blankToNull(mark.postalCode),
      );
    } catch (e) {
      if (kDebugMode) {
        print('Reverse geocode (parts) error $e');
      }
      return null;
    }
  }

  String? _blankToNull(String? value) =>
      value == null || value.trim().isEmpty ? null : value.trim();

  /// Search without Places: the platform geocoder, which takes a whole
  /// address rather than a fragment. Weaker, but it keeps the field
  /// working when no key is configured.
  Future<List<PlaceSuggestion>> _searchViaGeocoder(String query) async {
    try {
      final locations = await _geocoding?.locationFromAddress(query) ?? [];

      final results = <PlaceSuggestion>[];

      // Capped at five: the designs show four, and each entry costs a
      // reverse lookup to get a readable label.
      for (final location in locations.take(5)) {
        final place = await addressAt(location.latitude, location.longitude);

        if (place != null) {
          results.add(place);
        }
      }

      return results;
    } catch (e) {
      // Geocoder throws rather than returning empty when nothing matches,
      // which for a typeahead is a normal state, not an error.
      if (kDebugMode) {
        print('Geocoder search miss for "$query": $e');
      }
      return [];
    }
  }

  Future<PlaceSuggestion?> _resolveViaGeocoder(PlaceSuggestion suggestion) async {
    try {
      final locations =
          await _geocoding?.locationFromAddress(suggestion.fullAddress) ?? [];

      if (locations.isEmpty) {
        return null;
      }

      return suggestion.withPoint(
        locations.first.latitude,
        locations.first.longitude,
      );
    } catch (e) {
      if (kDebugMode) {
        print('Geocoder resolve error $e');
      }
      return null;
    }
  }

  /// Builds the two-line label the designs use out of a placemark.
  ///
  /// The title prefers the smallest meaningful name (a locality like
  /// "Maninagar") over the street line, because that is what a customer
  /// recognises as "where I am".
  PlaceSuggestion _fromPlacemark(
    Placemark mark,
    double latitude,
    double longitude,
  ) {
    final title = _firstNonEmpty([
      mark.subLocality,
      mark.locality,
      mark.name,
      mark.administrativeArea,
    ]);

    final subtitleParts = <String>[
      ...[
        mark.subLocality,
        mark.locality,
        mark.administrativeArea,
        mark.postalCode,
        mark.country,
      ].whereType<String>().where((part) => part.isNotEmpty),
    ]..removeWhere((part) => part == title);

    return PlaceSuggestion(
      id: 'geo:$latitude,$longitude',
      title: title.isEmpty ? '$latitude, $longitude' : title,
      subtitle: subtitleParts.join(', '),
      latitude: latitude,
      longitude: longitude,
    );
  }

  String _firstNonEmpty(List<String?> candidates) {
    for (final candidate in candidates) {
      if (candidate != null && candidate.trim().isNotEmpty) {
        return candidate.trim();
      }
    }

    return '';
  }
}
