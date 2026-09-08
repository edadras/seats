/// The shapes the check-in API speaks in.
///
/// Written by hand rather than generated: there are five of them, they change when the API's
/// contract changes, and a generator would be a build step to maintain for no benefit at this size.
library;

class CheckinEvent {
  const CheckinEvent({
    required this.id,
    required this.publicId,
    required this.name,
    this.startsAt,
    this.timezone,
    this.status,
  });

  final String id;
  final String publicId;
  final String name;
  final DateTime? startsAt;
  final String? timezone;
  final String? status;

  factory CheckinEvent.fromJson(Map<String, dynamic> json) => CheckinEvent(
        id: json['id'] as String,
        publicId: json['public_id'] as String? ?? '',
        name: json['name'] as String? ?? 'Event',
        startsAt: json['starts_at'] != null ? DateTime.tryParse(json['starts_at'] as String) : null,
        timezone: json['timezone'] as String?,
        status: json['status'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'public_id': publicId,
        'name': name,
        'starts_at': startsAt?.toIso8601String(),
        'timezone': timezone,
        'status': status,
      };
}

/// Every answer a scan can give. The API always returns 200 with one of these — "already used" is
/// a successful answer to a valid question, not an HTTP error — so the app branches here and
/// nowhere else.
enum ScanResult {
  valid,
  alreadyUsed,
  cancelled,
  refunded,
  wrongEvent,
  invalid,
  queued;

  static ScanResult parse(String? value) => switch (value) {
        'valid' => ScanResult.valid,
        'already_used' => ScanResult.alreadyUsed,
        'cancelled' => ScanResult.cancelled,
        'refunded' => ScanResult.refunded,
        'wrong_event' => ScanResult.wrongEvent,
        _ => ScanResult.invalid,
      };

  bool get admits => this == ScanResult.valid;

  /// What the person on the door reads, at arm's length, in the dark.
  String get headline => switch (this) {
        ScanResult.valid => 'Come in',
        ScanResult.alreadyUsed => 'Already used',
        ScanResult.cancelled => 'Cancelled',
        ScanResult.refunded => 'Refunded',
        ScanResult.wrongEvent => 'Wrong event',
        ScanResult.invalid => 'Not a ticket',
        ScanResult.queued => 'Saved offline',
      };

  String get detail => switch (this) {
        ScanResult.valid => 'Admitted.',
        ScanResult.alreadyUsed => 'Someone has already come in on this ticket.',
        ScanResult.cancelled => 'This booking was cancelled.',
        ScanResult.refunded => 'This booking was refunded.',
        ScanResult.wrongEvent => 'This ticket is for another performance.',
        ScanResult.invalid => 'This code is not one of ours.',
        ScanResult.queued => 'No connection. It will be sent when you are back online.',
      };
}

class ScanOutcome {
  const ScanOutcome({
    required this.result,
    this.holderName,
    this.seat,
    this.firstScan,
    this.firstScanBy,
  });

  final ScanResult result;
  final String? holderName;
  final String? seat;

  /// When this ticket was first admitted, on an `already_used` answer.
  final DateTime? firstScan;

  /// And by which door or operator — which is the half that settles the argument.
  final String? firstScanBy;

  /// Reads whatever came back without ever throwing.
  ///
  /// Everything here is checked rather than cast. A shape this did not expect must degrade to a
  /// thinner answer, never to an exception: an exception on this path leaves the volunteer holding
  /// a phone that says nothing at all, which is the one outcome a door cannot work with.
  factory ScanOutcome.fromJson(Map<String, dynamic> json) {
    final ticket = json['ticket'];
    final seat = ticket is Map ? ticket['seat'] : null;

    String? part(dynamic map, String key) {
      final value = map is Map ? map[key] : null;

      return value is String && value.isNotEmpty ? value : null;
    }

    final parts = <String>[
      if (part(seat, 'section') != null) part(seat, 'section')!,
      if (part(seat, 'row') != null) 'row ${part(seat, 'row')}',
      if (part(seat, 'label') != null) 'seat ${part(seat, 'label')}',
    ];

    // `first_scan` is an object — when, by which device, by which operator — not a timestamp.
    final firstScan = json['first_scan'];
    final scannedAt = part(firstScan, 'scanned_at');

    return ScanOutcome(
      result: ScanResult.parse(json['result'] is String ? json['result'] as String : null),
      holderName: part(ticket, 'holder_name'),
      seat: parts.isEmpty ? null : parts.join(' · '),
      firstScan: scannedAt != null ? DateTime.tryParse(scannedAt) : null,
      firstScanBy: part(firstScan, 'operator') ?? part(firstScan, 'device'),
    );
  }

  static const queued = ScanOutcome(result: ScanResult.queued);
}

class EventStats {
  const EventStats({
    required this.total,
    required this.checkedIn,
    required this.allocated,
  });

  final int total;
  final int checkedIn;
  final int allocated;

  factory EventStats.fromJson(Map<String, dynamic> json) => EventStats(
        total: (json['seats_total'] as num?)?.toInt() ?? 0,
        checkedIn: (json['checked_in'] as num?)?.toInt() ?? 0,
        allocated: (json['allocated'] as num?)?.toInt() ?? 0,
      );

  int get remaining => (allocated - checkedIn).clamp(0, allocated);
}

/// A scan taken while there was no connection.
class PendingScan {
  const PendingScan({
    required this.clientScanId,
    required this.eventId,
    required this.token,
    required this.scannedAt,
  });

  final String clientScanId;
  final String eventId;
  final String token;
  final DateTime scannedAt;

  Map<String, dynamic> toJson() => {
        'client_scan_id': clientScanId,
        'event_id': eventId,
        'token': token,
        'scanned_at': scannedAt.toUtc().toIso8601String(),
      };

  factory PendingScan.fromJson(Map<String, dynamic> json) => PendingScan(
        clientScanId: json['client_scan_id'] as String,
        eventId: json['event_id'] as String,
        token: json['token'] as String,
        scannedAt: DateTime.parse(json['scanned_at'] as String),
      );
}

class ApiFailure implements Exception {
  const ApiFailure(this.message, {this.code, this.status});

  final String message;
  final String? code;
  final int? status;

  /// Whether this is worth queueing rather than showing. A refusal is an answer; a network
  /// failure is not, and the difference decides whether the door keeps moving.
  bool get isOffline => status == null;

  @override
  String toString() => message;
}
