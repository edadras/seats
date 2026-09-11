import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/models.dart';

/// Every ticket for one night, on the phone, so the door can say no with no signal at all.
///
/// The scanner always kept working offline in the sense that it lost nothing — a scan taken with
/// no connection went into a queue and was sent later. What it could not do was *decide*, so
/// everybody was admitted and the forgeries turned up in the morning. At a door that is the same
/// as having no check at all.
///
/// Two things make this safe to keep on a volunteer's own phone:
///
///   - **It holds hashes, never codes.** What the server sends is `sha256` of each ticket's token,
///     which is exactly what it already stores and compares against. A token is 160 bits of
///     randomness, so the hash cannot be walked back: a phone left in a taxi is a list of names and
///     seat numbers — what a printed door list has always been — and not a machine for minting
///     tickets.
///   - **It knows it is a moment, not a fact.** It carries the time it was taken and says so on
///     screen, because the difference between "this is not a ticket" and "this was not a ticket at
///     six o'clock" is somebody who bought at seven standing outside in the rain.
class DoorList {
  const DoorList({
    required this.eventId,
    required this.version,
    required this.takenAt,
    required this.tickets,
  });

  final String eventId;
  final String version;
  final DateTime takenAt;

  /// Keyed by the hash, because that is the only question ever asked of it.
  final Map<String, DoorTicket> tickets;

  int get count => tickets.length;

  factory DoorList.fromJson(Map<String, dynamic> json) {
    final rows = (json['tickets'] as List?) ?? const [];
    final tickets = <String, DoorTicket>{};

    for (final row in rows) {
      if (row is! Map) {
        continue;
      }

      final hash = row['h'];

      if (hash is String && hash.isNotEmpty) {
        tickets[hash] = DoorTicket.fromJson(row);
      }
    }

    return DoorList(
      eventId: json['event_id'] as String? ?? '',
      version: json['version'] as String? ?? '',
      takenAt: DateTime.tryParse(json['taken_at'] as String? ?? '') ?? DateTime.now(),
      tickets: tickets,
    );
  }

  Map<String, dynamic> toJson() => {
        'event_id': eventId,
        'version': version,
        'taken_at': takenAt.toIso8601String(),
        'tickets': tickets.entries.map((e) => e.value.toJson(e.key)).toList(),
      };

  /// What this list says about a code, given what this device has already let in.
  ///
  /// The local admissions are not a nicety: without them the second presentation of the same
  /// ticket at the same door, ninety seconds apart with no signal in between, would be admitted
  /// again — which is the easiest way there is to get two people into one seat.
  ScanOutcome verdict(String code, Map<String, DateTime> admittedHere) {
    final hash = hashOf(code);
    final here = admittedHere[hash];

    if (here != null) {
      return ScanOutcome(
        result: ScanResult.alreadyUsed,
        holderName: tickets[hash]?.name,
        seat: tickets[hash]?.seat,
        firstScan: here,
        decidedOffline: true,
        listTakenAt: takenAt,
      );
    }

    final ticket = tickets[hash];

    if (ticket == null) {
      // Deliberately not "not a ticket". This list is a copy of a moment, and a real ticket sold
      // after it was taken looks exactly like a forgery from here.
      return ScanOutcome(
        result: ScanResult.notOnList,
        decidedOffline: true,
        listTakenAt: takenAt,
      );
    }

    return ScanOutcome(
      result: ticket.result,
      holderName: ticket.name,
      seat: ticket.seat,
      firstScan: ticket.usedAt,
      decidedOffline: true,
      listTakenAt: takenAt,
    );
  }

  static String hashOf(String code) => sha256.convert(utf8.encode(code)).toString();
}

/// One row of the list. Short keys on the wire; this is the only place that knows them.
class DoorTicket {
  const DoorTicket({
    required this.state,
    this.name,
    this.seatParts = const [],
    this.type,
    this.usedAt,
  });

  final String state;
  final String? name;
  final List<String> seatParts;
  final String? type;
  final DateTime? usedAt;

  factory DoorTicket.fromJson(Map row) => DoorTicket(
        state: row['s'] as String? ?? 'issued',
        name: row['n'] as String?,
        seatParts: ((row['p'] as List?) ?? const []).whereType<String>().toList(),
        type: row['k'] as String?,
        usedAt: row['t'] is String ? DateTime.tryParse(row['t'] as String) : null,
      );

  Map<String, dynamic> toJson(String hash) => {
        'h': hash,
        's': state,
        if (name != null) 'n': name,
        if (seatParts.isNotEmpty) 'p': seatParts,
        if (type != null) 'k': type,
        if (usedAt != null) 't': usedAt!.toIso8601String(),
      };

  String? get seat => seatParts.isEmpty ? null : seatParts.join(' · ');

  /// The same four answers the server gives, from the same two columns — so a device deciding
  /// offline and a server deciding online cannot disagree about a ticket neither has changed.
  ScanResult get result => switch (state) {
        'used' => ScanResult.alreadyUsed,
        'refunded' => ScanResult.refunded,
        'cancelled' => ScanResult.cancelled,
        _ => ScanResult.valid,
      };
}

/// Where the list and this device's own admissions are kept.
///
/// One list at a time: a device scans one door of one night, and holding three nights' lists would
/// be three times the audience's names on a phone for no gain. Switching event throws the old one
/// away along with the admissions that belong to it.
class DoorListStore {
  static const _listKey = 'seatmap.doorlist';
  static const _admittedKey = 'seatmap.doorlist.admitted';

  Future<SharedPreferences> get _prefs => SharedPreferences.getInstance();

  Future<DoorList?> load(String eventId) async {
    final raw = (await _prefs).getString(_listKey);

    if (raw == null) {
      return null;
    }

    try {
      final list = DoorList.fromJson(jsonDecode(raw) as Map<String, dynamic>);

      return list.eventId == eventId ? list : null;
    } catch (_) {
      return null;
    }
  }

  Future<void> save(DoorList list) async {
    final prefs = await _prefs;
    final held = await load(list.eventId);

    // A list for a different night is a different door. Its admissions go with it.
    if (held == null) {
      await prefs.remove(_admittedKey);
    }

    await prefs.setString(_listKey, jsonEncode(list.toJson()));
  }

  Future<void> clear() async {
    final prefs = await _prefs;

    await prefs.remove(_listKey);
    await prefs.remove(_admittedKey);
  }

  /// The hashes this device has admitted, and when.
  Future<Map<String, DateTime>> admitted() async {
    final raw = (await _prefs).getString(_admittedKey);

    if (raw == null) {
      return {};
    }

    try {
      final map = jsonDecode(raw) as Map<String, dynamic>;

      return map.map((hash, at) => MapEntry(hash, DateTime.parse(at as String)));
    } catch (_) {
      return {};
    }
  }

  /// Written on every admission rather than held in memory: a door phone gets reloaded, and a
  /// reload that forgets who came in is a reload that lets them all in again.
  Future<void> admit(String hash, DateTime at) async {
    final held = await admitted();

    held[hash] = at;

    await (await _prefs).setString(
      _admittedKey,
      jsonEncode(held.map((hash, at) => MapEntry(hash, at.toIso8601String()))),
    );
  }
}
