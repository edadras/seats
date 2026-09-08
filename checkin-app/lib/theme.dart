import 'package:flutter/material.dart';

/// The scanner's look.
///
/// Dark, high contrast, large type. This is used at a door, at night, at arm's length, often by
/// someone wearing gloves — so touch targets are big, the answer is the largest thing on screen,
/// and nothing important is carried by colour alone.
class ScannerTheme {
  static const ink = Color(0xFF0B0D12);
  static const surface = Color(0xFF161A23);
  static const surfaceRaised = Color(0xFF1F2430);
  static const border = Color(0xFF2C3242);
  static const text = Color(0xFFEDF0F6);
  static const muted = Color(0xFF98A1B3);
  static const accent = Color(0xFF5B63F0);

  static const admit = Color(0xFF1FA971);
  static const refuse = Color(0xFFD8443C);
  static const warn = Color(0xFFCE8A21);

  static ThemeData build() {
    const scheme = ColorScheme.dark(
      primary: accent,
      onPrimary: Colors.white,
      surface: surface,
      onSurface: text,
      error: refuse,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: ink,
      // The bundled family, named explicitly. CanvasKit has no system fonts to fall back to, so a
      // family it does not hold renders nothing at all.
      fontFamily: 'Roboto',
      appBarTheme: const AppBarTheme(
        backgroundColor: ink,
        surfaceTintColor: Colors.transparent,
        foregroundColor: text,
        centerTitle: false,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surfaceRaised,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: accent, width: 2),
        ),
        labelStyle: const TextStyle(color: muted),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          // 56 high: a door is not a place for a 40px button.
          minimumSize: const Size.fromHeight(56),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          textStyle: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          foregroundColor: text,
          side: const BorderSide(color: border),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        ),
      ),
      snackBarTheme: const SnackBarThemeData(
        backgroundColor: surfaceRaised,
        contentTextStyle: TextStyle(color: text),
        behavior: SnackBarBehavior.floating,
      ),
    );
  }
}
