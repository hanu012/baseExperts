import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:service_marketplace/constants/constant.dart';

/// Every language must carry every key, with the same `{}` placeholders.
///
/// easy_localization falls back to English for a missing key and fills
/// `{}` left to right, so a missing key shows English mid-screen and a
/// dropped `{}` silently loses a number — neither fails at runtime.
Map<String, String> _load(String code) {
  final file = File('assets/translations/$code.json');
  return Map<String, String>.from(jsonDecode(file.readAsStringSync()) as Map);
}

int _placeholders(String value) => '{}'.allMatches(value).length;

void main() {
  final english = _load(Constants.english);

  test('every StringRes key exists in en.json', () {
    final source = File('lib/constants/string_res.dart').readAsStringSync();
    final keys = RegExp(r"static const String \w+ = '(\w+)';")
        .allMatches(source)
        .map((m) => m.group(1)!)
        .toSet();

    expect(keys, isNotEmpty);
    expect(keys.difference(english.keys.toSet()), isEmpty,
        reason: 'StringRes keys with no English text');
  });

  for (final code in Constants.languages.keys.where((c) => c != Constants.english)) {
    group('$code.json', () {
      final translated = _load(code);

      test('has exactly the English keys', () {
        expect(english.keys.toSet().difference(translated.keys.toSet()), isEmpty,
            reason: 'missing in $code.json');
        expect(translated.keys.toSet().difference(english.keys.toSet()), isEmpty,
            reason: 'only in $code.json');
      });

      test('keeps every {} placeholder', () {
        final mismatched = english.keys
            .where((k) => translated.containsKey(k))
            .where((k) => _placeholders(english[k]!) != _placeholders(translated[k]!))
            .toList();

        expect(mismatched, isEmpty);
      });

      test('has no empty values', () {
        expect(translated.entries.where((e) => e.value.trim().isEmpty).map((e) => e.key), isEmpty);
      });
    });
  }
}
