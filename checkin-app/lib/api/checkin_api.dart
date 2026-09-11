import 'dart:async';
import 'dart:convert';
import 'dart:io' show SocketException;

import 'package:http/http.dart' as http;

import '../l10n/strings.dart';
import '../storage/door_list.dart';
import 'models.dart';

/// The check-in API, as this app uses it.
///
/// Every method either returns an answer or throws an [ApiFailure] that says whether the failure
/// was a refusal or a lack of connection. The door depends on that distinction: a refusal is
/// shown to the person holding the phone, a lack of connection is queued and the queue keeps the
/// line moving.
///
/// Only transport failures are turned into "no connection". A bug in this app's own decoding must
/// not be reported to a door as an outage and quietly queued — it would look like everything was
/// working right up until the numbers did not add up.
class CheckinApi {
  CheckinApi({required this.baseUrl, http.Client? client, this.token})
      : _client = client ?? http.Client();

  final String baseUrl;
  final http.Client _client;

  String? token;

  static const _timeout = Duration(seconds: 12);

  Uri _url(String path) => Uri.parse('${baseUrl.replaceAll(RegExp(r'/$'), '')}/v1/checkin$path');

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        // The door's language travels with every call, so a refusal the *server* composes — an
        // expired pairing code, a device that is not allowed on this event — comes back in the
        // language the volunteer is reading.
        'X-Seatmap-Locale': Strings.code,
        if (token != null) 'Authorization': 'Bearer $token',
      };

  /// Exchange a single-use pairing code for a device token.
  Future<({String token, List<CheckinEvent> events, String deviceName})> pair({
    required String pairingCode,
    required String deviceName,
  }) async {
    final json = await _post('/auth/token', {
      'pairing_code': pairingCode,
      'device_name': deviceName,
    }, authenticated: false);

    return (
      token: json['token'] as String,
      deviceName: (json['device'] as Map<String, dynamic>?)?['name'] as String? ?? deviceName,
      events: ((json['events'] as List?) ?? const [])
          .map((e) => CheckinEvent.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }

  Future<List<CheckinEvent>> events() async {
    final json = await _get('/events');

    return ((json['data'] as List?) ?? const [])
        .map((e) => CheckinEvent.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  /// Take a copy of the door list for one night.
  ///
  /// Several thousand rows, asked for once before the house opens and then only when somebody
  /// presses refresh. The server tags it, so asking again when nothing has changed costs a round
  /// trip and no body at all — which is what makes it reasonable to re-take it during an interval.
  Future<DoorList> doorList(String eventId) async {
    return DoorList.fromJson(await _get('/events/$eventId/door-list'));
  }

  Future<ScanOutcome> scan({
    required String eventId,
    required String ticketToken,
    required String clientScanId,
    DateTime? scannedAt,
  }) async {
    final json = await _post('/scan', {
      'event_id': eventId,
      'token': ticketToken,
      'client_scan_id': clientScanId,
      'scanned_at': (scannedAt ?? DateTime.now()).toUtc().toIso8601String(),
    });

    return ScanOutcome.fromJson(json);
  }

  /// Replay a batch taken offline. The server orders by `scanned_at` before applying, so whoever
  /// physically walked in first is the one recorded as admitted.
  Future<List<ScanOutcome>> sync(List<PendingScan> scans) async {
    final json = await _post('/sync', {'scans': scans.map((s) => s.toJson()).toList()});

    return ((json['results'] as List?) ?? const [])
        .map((r) => ScanOutcome.fromJson(r as Map<String, dynamic>))
        .toList();
  }

  Future<EventStats> stats(String eventId) async {
    return EventStats.fromJson(await _get('/events/$eventId/stats'));
  }

  Future<Map<String, dynamic>> _get(String path) async {
    try {
      return _decode(await _client.get(_url(path), headers: _headers).timeout(_timeout));
    } on http.ClientException {
      throw ApiFailure(Strings.t('failure.offline'));
    } on TimeoutException {
      throw ApiFailure(Strings.t('failure.offline'));
    } on SocketException {
      throw ApiFailure(Strings.t('failure.offline'));
    }
  }

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> body, {
    bool authenticated = true,
  }) async {
    try {
      // Built before the call, not inline: `a ? b : c ..remove(x)` applies the cascade to the
      // whole conditional, which stripped the token from authenticated requests too.
      final headers = _headers;

      if (!authenticated) {
        headers.remove('Authorization');
      }

      final response = await _client
          .post(_url(path), headers: headers, body: jsonEncode(body))
          .timeout(_timeout);

      return _decode(response);
    } on http.ClientException {
      throw ApiFailure(Strings.t('failure.offline'));
    } on TimeoutException {
      throw ApiFailure(Strings.t('failure.offline'));
    } on SocketException {
      throw ApiFailure(Strings.t('failure.offline'));
    }
  }

  Map<String, dynamic> _decode(http.Response response) {
    Map<String, dynamic> body;

    try {
      body = jsonDecode(response.body) as Map<String, dynamic>;
    } catch (_) {
      body = const {};
    }

    if (response.statusCode >= 400) {
      final error = body['error'] as Map<String, dynamic>?;

      throw ApiFailure(
        error?['message'] as String? ?? Strings.t('failure.generic'),
        code: error?['code'] as String?,
        status: response.statusCode,
      );
    }

    return body;
  }
}
