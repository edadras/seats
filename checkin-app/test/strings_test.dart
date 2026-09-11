import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:seatmap_checkin/l10n/strings.dart';
import 'package:seatmap_checkin/l10n/strings.g.dart';

/// The door speaks six languages, and two of them are not written the way English is.
///
/// What is worth testing here is what a volunteer would notice and could not fix: a date in a
/// calendar they do not use, a count in digits they do not read, and a missing string on the one
/// screen that is only read in an emergency.
void main() {
  setUp(() => Strings.use('en'));

  group('the catalogue', () {
    test('every locale carries every key English does', () {
      final english = kMessages['en']!.keys.toSet();

      for (final locale in kLocales) {
        final theirs = kMessages[locale.code]!;

        expect(
          english.difference(theirs.keys.toSet()),
          isEmpty,
          reason: '${locale.code} is missing keys',
        );

        for (final entry in theirs.entries) {
          expect(entry.value.trim(), isNotEmpty, reason: '${locale.code}/${entry.key} is empty');
        }
      }
    });

    test('every answer a scan can give has a headline and a detail in every language', () {
      // The generated copy is what the door actually reads, so this asserts against that rather
      // than against the PHP it came from.
      const results = [
        'valid', 'alreadyUsed', 'cancelled', 'refunded', 'wrongEvent', 'invalid', 'queued',
        'notOnList',
      ];

      for (final locale in kLocales) {
        Strings.use(locale.code);

        for (final result in results) {
          expect(Strings.t('result.$result.headline'), isNotEmpty);
          expect(Strings.t('result.$result.detail'), isNotEmpty);
        }
      }
    });

    test('a missing key degrades to something readable, never to nothing', () {
      expect(Strings.t('no.such.key'), 'key');
    });

    test('placeholders are substituted', () {
      Strings.use('en');

      expect(Strings.t('status.waiting', {'count': 3}), '3 waiting');
    });
  });

  group('choosing a language', () {
    test('the URL wins, then the stored choice, then the phone', () {
      expect(
        Strings.resolve(fromUrl: 'de', stored: 'fr', systemLocales: const [Locale('it')]),
        'de',
      );
      expect(Strings.resolve(stored: 'fr', systemLocales: const [Locale('it')]), 'fr');
      expect(Strings.resolve(systemLocales: const [Locale('it')]), 'it');
    });

    test('region is dropped rather than matched', () {
      expect(Strings.resolve(systemLocales: const [Locale('fa', 'AF')]), 'fa');
    });

    test('a language we do not speak falls through to English', () {
      expect(
        Strings.resolve(fromUrl: 'sv', stored: 'nope', systemLocales: const [Locale('pt')]),
        'en',
      );
    });
  });

  group('numbers and dates', () {
    test('Persian and Arabic count in their own digits', () {
      Strings.use('fa');
      expect(Strings.number(1204), '۱۲۰۴');

      Strings.use('ar');
      expect(Strings.number(1204), '١٢٠٤');

      Strings.use('de');
      expect(Strings.number(1204), '1204');
    });

    test('a clock time is shaped too, and keeps both digits', () {
      Strings.use('fa');
      expect(Strings.time(DateTime(2026, 9, 29, 7, 5)), '۰۷:۰۵');
    });

    /*
     * Every one of these was read out of ICU — the same library the server and the panel format
     * with — rather than worked out here, so this test fails if the scanner and the ticket in
     * somebody's hand would ever disagree about which day it is.
     *
     * The pair either side of Nowruz 1405 is the one that matters: the other common algorithm,
     * Birashk's 2820-year cycle, makes 1404 a leap year and puts that boundary a day out.
     */
    test('Gregorian dates convert to the Persian calendar, exactly as ICU does', () {
      final cases = <(DateTime, int, int, int)>[
        (DateTime(2026, 3, 21), 1405, 1, 1),
        (DateTime(2026, 3, 20), 1404, 12, 29),
        (DateTime(2025, 3, 21), 1404, 1, 1),
        // 1403 *is* a leap year, so its Esfand has a thirtieth day and 1404 does not.
        (DateTime(2025, 3, 20), 1403, 12, 30),
        (DateTime(2026, 9, 29), 1405, 7, 7),
        (DateTime(2026, 12, 31), 1405, 10, 10),
        (DateTime(2027, 1, 1), 1405, 10, 11),
        (DateTime(2000, 1, 1), 1378, 10, 11),
        (DateTime(1979, 2, 11), 1357, 11, 22),
      ];

      for (final (date, year, month, day) in cases) {
        final persian = PersianDate.from(date);

        expect(
          (persian.year, persian.month, persian.day),
          (year, month, day),
          reason: '$date',
        );
      }
    });

    test('and the leap rule is ICU\'s, not the 2820-year one', () {
      expect(PersianDate.isLeapYear(1403), isTrue);
      expect(PersianDate.isLeapYear(1404), isFalse);
    });

    test('a Persian date reads in the Persian calendar, month name and all', () {
      Strings.use('fa');

      // ۷ مهر ۱۴۰۵ — the same evening an English reader sees as 29 Sep 2026.
      expect(Strings.dateTime(DateTime(2026, 9, 29, 19, 30)), '۷ مهر ۱۴۰۵ — ۱۹:۳۰');
    });

    test('everyone else keeps the Gregorian calendar', () {
      Strings.use('ar');
      expect(Strings.dateTime(DateTime(2026, 9, 29, 19, 30)), '٢٩ سبتمبر ٢٠٢٦ — ١٩:٣٠');

      Strings.use('de');
      expect(Strings.dateTime(DateTime(2026, 9, 29, 19, 30)), '29. Sep 2026 · 19:30');
    });
  });

  group('direction', () {
    test('Persian and Arabic read right to left, the rest do not', () {
      Strings.use('fa');
      expect(Strings.direction, TextDirection.rtl);

      Strings.use('ar');
      expect(Strings.direction, TextDirection.rtl);

      Strings.use('fr');
      expect(Strings.direction, TextDirection.ltr);
    });
  });
}
