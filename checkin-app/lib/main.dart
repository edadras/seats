import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'api/checkin_api.dart';
import 'api/models.dart';
import 'l10n/strings.dart';
import 'screens/event_screen.dart';
import 'screens/pair_screen.dart';
import 'screens/scan_screen.dart';
import 'storage/device_store.dart';
import 'storage/scan_queue.dart';
import 'theme.dart';

/// Seatmap's door scanner.
///
/// A web app rather than a store app on purpose: a venue hands a phone to a volunteer twenty
/// minutes before doors, and "open this link" is a thing that can happen twenty minutes before
/// doors. An app store review is not.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // The language is settled before the first frame, not after it. A scanner that opens in English
  // and turns Persian a moment later has already been handed to somebody.
  Strings.use(Strings.resolve(
    fromUrl: Uri.base.queryParameters['lang'],
    stored: await DeviceStore().locale(),
    systemLocales: WidgetsBinding.instance.platformDispatcher.locales,
  ));

  runApp(const CheckinApp());
}

class CheckinApp extends StatefulWidget {
  const CheckinApp({super.key});

  /// Change the language of the whole running app, from anywhere in it.
  static void speak(BuildContext context, String code) {
    context.findAncestorStateOfType<_CheckinAppState>()?._speak(code);
  }

  @override
  State<CheckinApp> createState() => _CheckinAppState();
}

class _CheckinAppState extends State<CheckinApp> {
  Future<void> _speak(String code) async {
    await DeviceStore().saveLocale(code);

    if (!mounted) return;

    setState(() => Strings.use(code));
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      // Rebuilt when the language changes, so nothing anywhere below has to listen for it.
      key: ValueKey(Strings.code),
      onGenerateTitle: (context) => Strings.t('title'),
      debugShowCheckedModeBanner: false,
      theme: ScannerTheme.build(),
      locale: Strings.locale,
      // Flutter's own strings — the words in a dialog barrier, a text-field menu, a semantics
      // announcement — which this app's catalogue cannot reach.
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      supportedLocales: Strings.available.map((l) => Locale(l.code)),
      home: const _Root(),
    );
  }
}

class _Root extends StatefulWidget {
  const _Root();

  @override
  State<_Root> createState() => _RootState();
}

class _RootState extends State<_Root> {
  final _store = DeviceStore();
  final _queue = ScanQueue();

  CheckinApi? _api;
  String _deviceName = '';
  List<CheckinEvent> _events = const [];
  CheckinEvent? _event;
  int _queued = 0;

  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _restore();
  }

  /// Come back exactly where the shift left off: same server, same device, same event.
  Future<void> _restore() async {
    final token = await _store.token();
    final baseUrl = await _store.baseUrl() ?? _defaultBaseUrl();

    if (token == null) {
      setState(() => _loading = false);

      return;
    }

    final events = await _store.events();
    final selectedId = await _store.selectedEventId();
    final queued = await _queue.length();

    setState(() {
      _api = CheckinApi(baseUrl: baseUrl, token: token);
      _deviceName = '';
      _events = events;
      _event = events.where((e) => e.id == selectedId).firstOrNull;
      _queued = queued;
      _loading = false;
    });

    _deviceName = await _store.deviceName() ?? Strings.t('thisDevice');

    unawaitedRefresh();
  }

  /// The event list can change during a run — a second night added, access revoked — so it is
  /// refreshed in the background rather than only at pairing.
  void unawaitedRefresh() {
    final api = _api;

    if (api == null) {
      return;
    }

    api.events().then((events) async {
      await _store.saveEvents(events);

      if (!mounted) return;

      setState(() {
        _events = events;
        _event = events.where((e) => e.id == _event?.id).firstOrNull;
      });
    }).catchError((_) {
      // Offline, or the token has been revoked. The stored list is what the door runs on until
      // the next successful refresh.
    });
  }

  /// The address this app was served from, which is the right guess in the common case: the
  /// scanner is hosted by the same Seatmap instance it talks to.
  String _defaultBaseUrl() {
    final base = Uri.base;

    return '${base.scheme}://${base.authority}';
  }

  Future<void> _onPaired({
    required String baseUrl,
    required String token,
    required String deviceName,
    required List<CheckinEvent> events,
  }) async {
    await _store.savePairing(
      baseUrl: baseUrl,
      token: token,
      deviceName: deviceName,
      events: events,
    );

    setState(() {
      _api = CheckinApi(baseUrl: baseUrl, token: token);
      _deviceName = deviceName;
      _events = events;
      _event = events.length == 1 ? events.first : null;
    });

    if (_event != null) {
      await _store.selectEvent(_event!.id);
    }
  }

  Future<void> _select(CheckinEvent event) async {
    await _store.selectEvent(event.id);

    setState(() => _event = event);
  }

  Future<void> _unpair() async {
    final queued = await _queue.length();

    if (!mounted) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        backgroundColor: ScannerTheme.surface,
        title: Text(Strings.t('unpair.title')),
        content: Text(
          queued > 0
              // Said plainly: those are people who are already inside.
              ? Strings.t('unpair.withQueue', {'count': Strings.number(queued)})
              : Strings.t('unpair.clean'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: Text(Strings.t('cancel')),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              minimumSize: const Size(110, 44),
              backgroundColor: ScannerTheme.refuse,
            ),
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(Strings.t('unpair.confirm')),
          ),
        ],
      ),
    );

    if (confirmed != true) {
      return;
    }

    await _store.forget();

    setState(() {
      _api = null;
      _events = const [];
      _event = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final api = _api;

    if (api == null) {
      return FutureBuilder<String?>(
        future: _store.baseUrl(),
        builder: (context, snapshot) => PairScreen(
          initialBaseUrl: snapshot.data ?? _defaultBaseUrl(),
          onPaired: ({
            required String baseUrl,
            required String token,
            required String deviceName,
            required List<CheckinEvent> events,
          }) =>
              _onPaired(baseUrl: baseUrl, token: token, deviceName: deviceName, events: events),
        ),
      );
    }

    final event = _event;

    if (event == null) {
      return EventScreen(
        events: _events,
        selectedId: null,
        onSelect: _select,
        deviceName: _deviceName,
        queued: _queued,
        onUnpair: _unpair,
        onRefresh: () async => unawaitedRefresh(),
      );
    }

    return ScanScreen(
      key: ValueKey(event.id),
      api: api,
      event: event,
      queue: _queue,
      onChangeEvent: () => setState(() => _event = null),
    );
  }
}
