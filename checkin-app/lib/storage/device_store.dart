import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../api/models.dart';

/// What the device remembers between reloads.
///
/// A door phone gets reloaded — by a lock screen, by a browser tab being reopened, by someone
/// closing it by accident mid-queue. Everything needed to carry on is here, and nothing else is:
/// no scans of other people's tickets, no buyer details.
class DeviceStore {
  static const _keyBaseUrl = 'seatmap.base_url';
  static const _keyToken = 'seatmap.token';
  static const _keyDeviceName = 'seatmap.device_name';
  static const _keyEvents = 'seatmap.events';
  static const _keyEventId = 'seatmap.event_id';

  /// The language this device was told to speak.
  ///
  /// Named to match the panel's and the console's key, though it is not shared with them:
  /// shared_preferences writes it as `flutter.seatmap.locale`. `web/index.html` reads that exact
  /// key so the boot screen and the first frame cannot disagree.
  static const _keyLocale = 'seatmap.locale';

  Future<SharedPreferences> get _prefs => SharedPreferences.getInstance();

  Future<String?> baseUrl() async => (await _prefs).getString(_keyBaseUrl);
  Future<String?> token() async => (await _prefs).getString(_keyToken);
  Future<String?> deviceName() async => (await _prefs).getString(_keyDeviceName);
  Future<String?> selectedEventId() async => (await _prefs).getString(_keyEventId);
  Future<String?> locale() async => (await _prefs).getString(_keyLocale);

  Future<void> saveLocale(String code) async => (await _prefs).setString(_keyLocale, code);

  Future<void> savePairing({
    required String baseUrl,
    required String token,
    required String deviceName,
    required List<CheckinEvent> events,
  }) async {
    final prefs = await _prefs;

    await prefs.setString(_keyBaseUrl, baseUrl);
    await prefs.setString(_keyToken, token);
    await prefs.setString(_keyDeviceName, deviceName);

    await saveEvents(events);
  }

  Future<void> saveEvents(List<CheckinEvent> events) async {
    final prefs = await _prefs;

    await prefs.setString(_keyEvents, jsonEncode(events.map((e) => e.toJson()).toList()));
  }

  /// The last known event list, so a scanner that opens without a connection still knows which
  /// door it is on rather than starting from nothing.
  Future<List<CheckinEvent>> events() async {
    final raw = (await _prefs).getString(_keyEvents);

    if (raw == null) {
      return const [];
    }

    try {
      return (jsonDecode(raw) as List)
          .map((e) => CheckinEvent.fromJson(e as Map<String, dynamic>))
          .toList();
    } catch (_) {
      return const [];
    }
  }

  Future<void> selectEvent(String? eventId) async {
    final prefs = await _prefs;

    if (eventId == null) {
      await prefs.remove(_keyEventId);
    } else {
      await prefs.setString(_keyEventId, eventId);
    }
  }

  /// Unpair. The queue is deliberately not cleared here — see ScanQueue, and the language is not
  /// forgotten either: the next volunteer is standing at the same door in the same country.
  Future<void> forget() async {
    final prefs = await _prefs;

    await prefs.remove(_keyToken);
    await prefs.remove(_keyDeviceName);
    await prefs.remove(_keyEvents);
    await prefs.remove(_keyEventId);
  }
}
