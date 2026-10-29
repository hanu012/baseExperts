import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../constants/constant.dart';
import '../constants/string_res.dart';
import '../utils/utils.dart';
import 'base_services.dart';
import 'base_text.dart';

/// The language list shared by all three profile screens. Each language is
/// shown in its own script (see [Constants.languages]).
///
/// Closes itself and reports the tapped code; the caller applies it, since
/// the caller also owns saving it to the server. Lists only the languages
/// the admin has enabled (Settings → App).
Future<void> showLanguagePicker({
  required String current,
  required ValueChanged<String> onSelected,
}) async {
  final enabled = await Utils.enabledLanguages();

  Get.bottomSheet(
    LanguagePickerSheet(
      current: current,
      available: enabled,
      onSelected: (code) {
        Get.back();
        if (code != current) {
          onSelected(code);
        }
      },
    ),
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
  );
}

class LanguagePickerSheet extends StatelessWidget {
  const LanguagePickerSheet({
    super.key,
    required this.current,
    required this.onSelected,
    this.available,
  });

  final String current;
  final ValueChanged<String> onSelected;

  /// Codes to offer; every bundled language when null.
  final List<String>? available;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.vertical(top: Radius.circular(26.getSize)),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: EdgeInsets.fromLTRB(20.getSize, 12.getSize, 20.getSize, 18.getSize),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  height: 4.getSize,
                  width: 44.getSize,
                  decoration: BoxDecoration(
                    color: ServiceTokens.stroke2,
                    borderRadius: BorderRadius.circular(4.getSize),
                  ),
                ),
              ),
              18.heightSpacer,
              BaseTextDMSans(
                text: tr(StringRes.chooseLanguage),
                fontSize: 18,
                fontWeight: FontWeight.w800,
                color: ServiceTokens.text,
                textAlign: TextAlign.start,
              ),
              16.heightSpacer,
              for (final entry in Constants.languages.entries.where(
                (e) => available == null || available!.contains(e.key),
              )) ...[
                option(entry.key, entry.value),
                10.heightSpacer,
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget option(String code, String name) {
    final selected = code == current;

    return InkWell(
      key: ValueKey('language-$code'),
      onTap: () => onSelected(code),
      borderRadius: BorderRadius.circular(14.getSize),
      child: Container(
        padding: EdgeInsets.symmetric(horizontal: 16.getSize, vertical: 14.getSize),
        decoration: BoxDecoration(
          color: selected ? ServiceTokens.tileFill : ServiceTokens.card,
          borderRadius: BorderRadius.circular(14.getSize),
          border: Border.all(
            color: selected ? ServiceTokens.accent : ServiceTokens.stroke,
          ),
        ),
        child: Row(
          children: [
            Expanded(
              child: BaseTextDMSans(
                text: name,
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.text,
                textAlign: TextAlign.start,
              ),
            ),
            if (selected)
              Icon(Icons.check_circle, color: ServiceTokens.accent, size: 22.getSize),
          ],
        ),
      ),
    );
  }
}
