import 'package:flutter/widgets.dart';

import 'strings.g.dart';

/// The scanner's words, and the two things about a language that are not words.
///
/// The catalogue itself is generated from `api/lang/<locale>/checkin.php` — see the note at the top
/// of `strings.g.dart`. What lives here is the part that cannot be generated: which language this
/// device is in, and how to write a number and a date in it.
///
/// There is no `BuildContext` lookup. A door scanner has one language for the whole shift, chosen
/// before anything paints and changed only by a deliberate act, so a global is honest about what
/// this is and keeps `t('scan.next')` short enough to read inside a widget tree.
class Strings {
  Strings._();

  static LocaleFacts _facts = kLocales.firstWhere((l) => l.code == 'en');
  static Map<String, String> _messages = kMessages['en']!;

  static LocaleFacts get facts => _facts;

  static String get code => _facts.code;

  static Locale get locale => Locale(_facts.code);

  static TextDirection get direction =>
      _facts.rtl ? TextDirection.rtl : TextDirection.ltr;

  static List<LocaleFacts> get available => kLocales;

  static bool supports(String? code) => kLocales.any((l) => l.code == code);

  /// Switch language. Callers persist the choice; this only decides what the next frame says.
  static void use(String code) {
    _facts = kLocales.firstWhere((l) => l.code == code, orElse: () => _facts);
    _messages = kMessages[_facts.code] ?? kMessages['en']!;
  }

  /// Which language this device should be in, given what we know.
  ///
  /// From the nearest signal outwards, the same order the server uses (ADR-0005 §4): what this URL
  /// asked for, then what this device was told once, then what the phone itself is set to. It ends
  /// at English rather than at nothing.
  static String resolve({String? fromUrl, String? stored, List<Locale> systemLocales = const []}) {
    if (supports(fromUrl)) {
      return fromUrl!;
    }

    if (supports(stored)) {
      return stored!;
    }

    for (final locale in systemLocales) {
      // Region is dropped rather than matched: we ship one Persian, and fa-AF should get it.
      if (supports(locale.languageCode)) {
        return locale.languageCode;
      }
    }

    return 'en';
  }

  /// `a.b.c`, with `:name` substituted. A missing key returns its own last segment rather than
  /// nothing: a button reading "next" is usable and a button reading nothing is not.
  static String t(String key, [Map<String, Object?> replace = const {}]) {
    var value = _messages[key] ?? kMessages['en']?[key] ?? key.split('.').last;

    replace.forEach((name, substitution) {
      value = value.replaceAll(':$name', substitution.toString());
    });

    return value;
  }

  /// A number in this language's digits. Persian and Arabic have their own, and a scanner counting
  /// people in Latin digits on a Persian screen reads as somebody else's machine.
  static String number(int value) {
    final digits = _facts.digits;

    if (digits == null) {
      return value.toString();
    }

    return value.toString().split('').map((c) {
      final index = int.tryParse(c);

      return index == null ? c : digits[index];
    }).join();
  }

  /// A clock time, always two digits, in this language's digits.
  static String time(DateTime value) {
    final local = value.toLocal();
    final hh = local.hour.toString().padLeft(2, '0');
    final mm = local.minute.toString().padLeft(2, '0');

    return _shape('$hh:$mm');
  }

  /// A date and a time, in this language's calendar.
  ///
  /// The calendar is the sharp end of this. A Persian reader handed "29 Sep 2026" has to convert it
  /// before it names a day, and the one thing a volunteer must not get wrong is which performance
  /// they are on the door for.
  static String dateTime(DateTime value) {
    final local = value.toLocal();
    final parts = _facts.persianCalendar
        ? PersianDate.from(local)
        : (year: local.year, month: local.month, day: local.day);

    return t('dateTime', {
      'day': number(parts.day),
      'month': t('months.${parts.month}'),
      'year': number(parts.year),
      'time': time(local),
    });
  }

  static String _shape(String text) {
    final digits = _facts.digits;

    if (digits == null) {
      return text;
    }

    return text.split('').map((c) {
      final index = int.tryParse(c);

      return index == null ? c : digits[index];
    }).join();
  }
}

/// Gregorian to Persian (Solar Hijri).
///
/// Written out rather than pulled in: `intl` does not do this calendar, the scanner must work with
/// no network, and every kilobyte arrives over a venue's wifi.
///
/// It is deliberately the *same* arithmetic ICU uses, which is what the server and the panel are
/// formatting with (`IntlDateFormatter` with the `persian` calendar). The other common
/// implementation — Birashk's 2820-year cycle — disagrees with ICU in some years, and 1404 is one
/// of them: it would put Nowruz a day out and have this scanner name a different day from the
/// ticket the person is holding. A test pins both against dates ICU was asked for directly.
class PersianDate {
  /// 1 Farvardin 1, as a Julian Day Number.
  static const _epoch = 1948320;

  /// Days elapsed before the first of each month, in an ordinary year.
  static const _monthStarts = [0, 31, 62, 93, 124, 155, 186, 216, 246, 276, 306, 336];

  static ({int year, int month, int day}) from(DateTime date) {
    final days = _gregorianToJdn(date.year, date.month, date.day) - _epoch;
    final year = 1 + _floorDiv(33 * days + 3, 12053);
    final dayOfYear = days - (365 * (year - 1) + _floorDiv(8 * year + 21, 33));
    final month = dayOfYear < 216 ? dayOfYear ~/ 31 : (dayOfYear - 6) ~/ 30;

    return (year: year, month: month + 1, day: dayOfYear - _monthStarts[month] + 1);
  }

  /// Whether Esfand has thirty days. ICU's rule, and the reason it is not Birashk's.
  static bool isLeapYear(int year) => _floorMod(25 * year + 11, 33) < 8;

  static int _gregorianToJdn(int year, int month, int day) {
    final a = (14 - month) ~/ 12;
    final y = year + 4800 - a;
    final m = month + 12 * a - 3;

    return day + (153 * m + 2) ~/ 5 + 365 * y + y ~/ 4 - y ~/ 100 + y ~/ 400 - 32045;
  }

  /// Dart truncates towards zero; these need the floor, and dates before the epoch are negative.
  static int _floorDiv(int a, int b) => (a - _floorMod(a, b)) ~/ b;

  static int _floorMod(int a, int b) => ((a % b) + b) % b;
}
