import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:seatmap_checkin/api/checkin_api.dart';
import 'package:seatmap_checkin/api/models.dart';
import 'package:seatmap_checkin/storage/scan_queue.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// What is worth testing here is the behaviour a door depends on: that every answer the API can
/// give maps to something a person can act on, that a lost connection is told apart from a
/// refusal, and that the offline queue does not lose or duplicate a scan.
void main() {
  group('scan results', () {
    test('every outcome the API can return has a headline', () {
      for (final value in [
        'valid',
        'already_used',
        'cancelled',
        'refunded',
        'wrong_event',
        'invalid',
      ]) {
        final result = ScanResult.parse(value);

        expect(result.headline, isNotEmpty);
        expect(result.detail, isNotEmpty);
      }
    });

    test('an unrecognised result is refused rather than admitted', () {
      // A future server that grows a new outcome must not accidentally let someone in.
      expect(ScanResult.parse('something_new').admits, isFalse);
      expect(ScanResult.parse(null).admits, isFalse);
      expect(ScanResult.valid.admits, isTrue);
    });

    test('a seat is assembled from whatever parts came back', () {
      final outcome = ScanOutcome.fromJson({
        'result': 'valid',
        'ticket': {
          'holder_name': 'Amina Farsi',
          'seat': {'section': 'Stalls', 'row': 'C', 'label': '14'},
        },
      });

      expect(outcome.result, ScanResult.valid);
      expect(outcome.seat, 'Stalls · row C · seat 14');
      expect(outcome.holderName, 'Amina Farsi');
    });

    test('standing room has no seat to name', () {
      final outcome = ScanOutcome.fromJson({
        'result': 'valid',
        'ticket': {'seat': {'section': '', 'row': '', 'label': ''}},
      });

      expect(outcome.seat, isNull);
    });

    test('a second scan says when it was first used, and by which door', () {
      // Verbatim from the API. `first_scan` is an object; reading it as a timestamp threw, and a
      // throw here is worse than any refusal — the volunteer is left holding a phone showing
      // nothing on the most common refusal there is.
      final outcome = ScanOutcome.fromJson({
        'result': 'already_used',
        'ticket': {
          'id': '01a082f5-423c-7297-8382-cc4f35ee182c',
          'status': 'used',
          'holder_name': 'Doorstep Test',
          'seat': {'section': 'Stalls', 'row': 'B', 'label': '13'},
        },
        'first_scan': {
          'scanned_at': '2026-09-08T22:14:23+00:00',
          'device': 'Door 1',
          'operator': null,
        },
      });

      expect(outcome.result, ScanResult.alreadyUsed);
      expect(outcome.firstScan, DateTime.parse('2026-09-08T22:14:23+00:00'));
      expect(outcome.firstScanBy, 'Door 1');
    });

    test('an operator is named ahead of the device that carried them', () {
      final outcome = ScanOutcome.fromJson({
        'result': 'already_used',
        'first_scan': {'scanned_at': '2026-09-08T22:14:23Z', 'device': 'Door 1', 'operator': 'Reza'},
      });

      expect(outcome.firstScanBy, 'Reza');
    });

    test('a shape we did not expect degrades instead of throwing', () {
      // Anything that reaches here is already parsed JSON, so the only defence left is not
      // trusting its shape. Every one of these once threw.
      expect(ScanOutcome.fromJson({}).result.admits, isFalse);
      expect(ScanOutcome.fromJson({'result': 7}).result.admits, isFalse);
      expect(ScanOutcome.fromJson({'result': 'valid', 'ticket': 'nope'}).seat, isNull);
      expect(ScanOutcome.fromJson({'result': 'valid', 'first_scan': 'yesterday'}).firstScan, isNull);
      expect(
        ScanOutcome.fromJson({'result': 'valid', 'first_scan': {'scanned_at': 'not a date'}}).firstScan,
        isNull,
      );
    });
  });

  group('api', () {
    test('a refusal carries its code and is not treated as offline', () async {
      final api = CheckinApi(
        baseUrl: 'https://example.test',
        token: 'device-token',
        client: MockClient((request) async => http.Response(
              jsonEncode({
                'error': {'code': 'forbidden', 'message': 'Not authorised for that event.'},
              }),
              403,
            )),
      );

      await expectLater(
        api.scan(eventId: 'e', ticketToken: 't', clientScanId: 'c'),
        throwsA(isA<ApiFailure>()
            .having((e) => e.isOffline, 'isOffline', isFalse)
            .having((e) => e.code, 'code', 'forbidden')),
      );
    });

    test('a network failure is offline, which is what makes it queueable', () async {
      final api = CheckinApi(
        baseUrl: 'https://example.test',
        token: 'device-token',
        client: MockClient((request) async => throw http.ClientException('Failed to fetch')),
      );

      await expectLater(
        api.scan(eventId: 'e', ticketToken: 't', clientScanId: 'c'),
        throwsA(isA<ApiFailure>().having((e) => e.isOffline, 'isOffline', isTrue)),
      );
    });

    test('a scan sends the event, the token and a stable client id', () async {
      http.Request? seen;

      final api = CheckinApi(
        baseUrl: 'https://example.test/',
        token: 'device-token',
        // Captured, not asserted here: an expectation that fails inside the handler would come
        // back through the client as a transport error and be reported as "no connection".
        client: MockClient((request) async {
          seen = request;

          return http.Response(jsonEncode({'result': 'valid'}), 200);
        }),
      );

      final outcome = await api.scan(
        eventId: 'event-1',
        ticketToken: 'TKTABC',
        clientScanId: 'scan-1',
      );

      final sent = jsonDecode(seen!.body) as Map<String, dynamic>;

      expect(outcome.result, ScanResult.valid);
      expect(seen!.url.path, '/v1/checkin/scan');
      expect(seen!.headers['Authorization'], 'Bearer device-token');
      expect(sent['event_id'], 'event-1');
      expect(sent['token'], 'TKTABC');
      expect(sent['client_scan_id'], 'scan-1');
      expect(sent['scanned_at'], isNotEmpty);
    });
  });

  group('offline queue', () {
    setUp(() => SharedPreferences.setMockInitialValues({}));

    test('a scan survives being written and read back', () async {
      final queue = ScanQueue();

      await queue.add(PendingScan(
        clientScanId: 'a',
        eventId: 'event-1',
        token: 'TKT1',
        scannedAt: DateTime.utc(2026, 9, 8, 19, 30),
      ));

      final all = await queue.all();

      expect(all, hasLength(1));
      expect(all.single.token, 'TKT1');
      expect(all.single.scannedAt, DateTime.utc(2026, 9, 8, 19, 30));
    });

    test('the same scan twice is one scan', () async {
      final queue = ScanQueue();
      final scan = PendingScan(
        clientScanId: 'a',
        eventId: 'event-1',
        token: 'TKT1',
        scannedAt: DateTime.utc(2026, 9, 8, 19, 30),
      );

      await queue.add(scan);
      await queue.add(scan);

      expect(await queue.length(), 1);
    });

    test('sending clears only what was sent', () async {
      final queue = ScanQueue();

      for (final id in ['a', 'b', 'c']) {
        await queue.add(PendingScan(
          clientScanId: id,
          eventId: 'event-1',
          token: 'TKT-$id',
          scannedAt: DateTime.utc(2026, 9, 8, 19, 30),
        ));
      }

      // 'c' arrived while the batch of a and b was in flight; it must not go with them.
      await queue.remove(['a', 'b']);

      final left = await queue.all();

      expect(left.map((s) => s.clientScanId), ['c']);
    });
  });
}

/// A tiny mock client, so the tests do not depend on `package:http`'s testing extra.
class MockClient extends http.BaseClient {
  MockClient(this.handler);

  final Future<http.Response> Function(http.Request request) handler;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final body = request is http.Request ? request.body : '';

    final response = await handler(http.Request(request.method, request.url)
      ..body = body
      ..headers.addAll(request.headers));

    return http.StreamedResponse(
      Stream.value(utf8.encode(response.body)),
      response.statusCode,
      headers: response.headers,
      request: request,
    );
  }
}
