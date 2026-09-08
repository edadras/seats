import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../api/models.dart';

/// Scans taken with no connection, waiting to be sent.
///
/// Written to storage on every append, not held in memory: the whole point is to survive the tab
/// being closed, and a queue that only survives while the app is running solves nothing.
///
/// It is not cleared on unpair. Somebody who signs a device out with twenty unsent scans on it has
/// twenty people already inside the building, and the record of them is worth more than the
/// tidiness of an empty queue.
class ScanQueue {
  static const _key = 'seatmap.queue';

  Future<SharedPreferences> get _prefs => SharedPreferences.getInstance();

  Future<List<PendingScan>> all() async {
    final raw = (await _prefs).getString(_key);

    if (raw == null) {
      return [];
    }

    try {
      return (jsonDecode(raw) as List)
          .map((e) => PendingScan.fromJson(e as Map<String, dynamic>))
          .toList();
    } catch (_) {
      return [];
    }
  }

  Future<int> length() async => (await all()).length;

  Future<void> add(PendingScan scan) async {
    final scans = await all();

    // A repeated scan of the same code on the same device is the same event, not two: the person
    // waved the phone twice because nothing happened on screen.
    if (scans.any((s) => s.clientScanId == scan.clientScanId)) {
      return;
    }

    scans.add(scan);
    await _write(scans);
  }

  /// Remove the ones the server has now accepted, by id rather than by count: a scan taken while
  /// the sync was in flight must not be dropped with the batch it was not part of.
  Future<void> remove(Iterable<String> clientScanIds) async {
    final done = clientScanIds.toSet();
    final scans = await all()..removeWhere((s) => done.contains(s.clientScanId));

    await _write(scans);
  }

  Future<void> _write(List<PendingScan> scans) async {
    final prefs = await _prefs;

    await prefs.setString(_key, jsonEncode(scans.map((s) => s.toJson()).toList()));
  }
}
