import 'package:flutter/material.dart';

import '../l10n/strings.dart';
import '../main.dart';
import '../theme.dart';

/// The language menu.
///
/// It is on the pairing screen and on the event screen, and on neither of the two screens a
/// volunteer uses in front of a queue: the language is chosen once, when the phone is handed over,
/// and a menu beside the torch button is a menu somebody opens by accident at the door.
///
/// Every language is listed in its own name, never in the reader's. Somebody looking for Persian is
/// looking for فارسی, and "Persian" is exactly the word they cannot read.
class LanguageButton extends StatelessWidget {
  const LanguageButton({super.key, this.compact = false});

  /// An icon on an app bar rather than a labelled button in a form.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final current = Strings.facts;

    return PopupMenuButton<String>(
      tooltip: Strings.t('language'),
      color: ScannerTheme.surface,
      position: PopupMenuPosition.under,
      onSelected: (code) => CheckinApp.speak(context, code),
      itemBuilder: (context) => [
        for (final locale in Strings.available)
          PopupMenuItem(
            value: locale.code,
            child: Row(
              children: [
                // Never colour or position alone: the tick says which one is on.
                Icon(
                  locale.code == current.code
                      ? Icons.radio_button_checked_rounded
                      : Icons.radio_button_unchecked_rounded,
                  size: 18,
                  color: locale.code == current.code ? ScannerTheme.accent : ScannerTheme.muted,
                ),
                const SizedBox(width: 10),
                // Each name in its own script, so it reads correctly whichever way this app is
                // currently laid out.
                Directionality(
                  textDirection: locale.rtl ? TextDirection.rtl : TextDirection.ltr,
                  child: Text(locale.native),
                ),
              ],
            ),
          ),
      ],
      child: compact
          ? const Padding(
              padding: EdgeInsets.all(12),
              child: Icon(Icons.translate_rounded, size: 22),
            )
          : Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                border: Border.all(color: ScannerTheme.border),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.translate_rounded, size: 18, color: ScannerTheme.muted),
                  const SizedBox(width: 8),
                  Text(current.native),
                  const Icon(Icons.arrow_drop_down_rounded, color: ScannerTheme.muted),
                ],
              ),
            ),
    );
  }
}
