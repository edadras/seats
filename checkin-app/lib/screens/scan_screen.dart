import 'dart:async';

import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../api/checkin_api.dart';
import '../api/models.dart';
import '../l10n/strings.dart';
import '../storage/door_list.dart';
import '../storage/scan_queue.dart';
import '../theme.dart';
import '../widgets/result_card.dart';
import '../widgets/status_bar.dart';

/// The door.
///
/// The camera runs continuously and the result covers the screen until it is dismissed, because
/// the alternative — a toast over a live camera — is unreadable at the moment it matters and
/// invites the next person to be scanned before anyone has read the last answer.
class ScanScreen extends StatefulWidget {
  const ScanScreen({
    super.key,
    required this.api,
    required this.event,
    required this.queue,
    required this.doorLists,
    required this.onChangeEvent,
  });

  final CheckinApi api;
  final CheckinEvent event;
  final ScanQueue queue;
  final DoorListStore doorLists;
  final VoidCallback onChangeEvent;

  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> {
  final _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const [BarcodeFormat.qrCode],
  );

  ScanOutcome? _outcome;
  bool _busy = false;
  bool _online = true;
  int _queued = 0;
  EventStats? _stats;
  Timer? _drain;

  /// The copy of the house this device is carrying, and the tickets it has let in on its own.
  DoorList? _doorList;
  Map<String, DateTime> _admitted = {};

  /// Scans this device decided one way and the server then decided another. Shown once, loudly,
  /// on the night — not left in a report somebody reads on Monday.
  List<ScanConflict> _conflicts = [];
  bool _takingList = false;

  /// The last code, so a QR sitting in front of the lens is not scanned forty times a second.
  String? _lastCode;
  DateTime _lastCodeAt = DateTime.fromMillisecondsSinceEpoch(0);

  @override
  void initState() {
    super.initState();

    _refreshQueue();
    _refreshStats();
    _loadDoorList();

    // Try the queue on a timer rather than on a connectivity event: the venue Wi-Fi that drops at
    // a door does not always announce itself, and a request that succeeds is the only proof.
    _drain = Timer.periodic(const Duration(seconds: 20), (_) => _drainQueue());
  }

  @override
  void dispose() {
    _drain?.cancel();
    _controller.dispose();
    super.dispose();
  }

  Future<void> _refreshQueue() async {
    final queued = await widget.queue.length();

    if (mounted) {
      setState(() => _queued = queued);
    }
  }

  Future<void> _refreshStats() async {
    try {
      final stats = await widget.api.stats(widget.event.id);

      if (mounted) {
        setState(() {
          _stats = stats;
          _online = true;
        });
      }
    } on ApiFailure catch (e) {
      if (mounted && e.isOffline) {
        setState(() => _online = false);
      }
    }
  }

  Future<void> _drainQueue() async {
    final pending = await widget.queue.all();

    if (pending.isEmpty) {
      return;
    }

    try {
      final results = await widget.api.sync(pending);

      await widget.queue.remove(pending.map((p) => p.clientScanId));

      final disagreed = _disagreements(pending, results);

      if (mounted) {
        setState(() {
          _online = true;
          _conflicts = [..._conflicts, ...disagreed];
        });
      }

      await _refreshQueue();
      await _refreshStats();
      // The house has moved while this device was deaf; the copy it is carrying has not.
      await _loadDoorList(refresh: true);
    } on ApiFailure catch (e) {
      // Still no connection. The queue stays exactly as it is; nothing is lost by trying again.
      if (mounted && e.isOffline) {
        setState(() => _online = false);
      }
    }
  }

  /// The copy of the house this device carries.
  ///
  /// Loaded from storage first and then refreshed if there is signal, in that order: the point of
  /// the thing is to be there when the network is not, so a failed refresh must never leave the
  /// door with less than it had a moment ago.
  Future<void> _loadDoorList({bool refresh = false}) async {
    final held = await widget.doorLists.load(widget.event.id);

    if (mounted && held != null) {
      setState(() => _doorList = held);
    }

    _admitted = await widget.doorLists.admitted();

    if (held != null && !refresh) {
      return;
    }

    try {
      final fresh = await widget.api.doorList(widget.event.id);

      await widget.doorLists.save(fresh);

      if (mounted) {
        setState(() => _doorList = fresh);
      }
    } on ApiFailure {
      // No signal, or none yet. Whatever was already on the device stands.
    }
  }

  /// Where the door and the server did not agree.
  ///
  /// Only admissions are compared. A door that refused somebody the server would have admitted is
  /// a person who came back to the desk and got in; a door that admitted somebody the server
  /// refuses is a person now sitting in the room, and that is the one worth a volunteer's
  /// attention before the interval.
  List<ScanConflict> _disagreements(List<PendingScan> sent, List<ScanOutcome> results) {
    final found = <ScanConflict>[];

    for (var i = 0; i < sent.length && i < results.length; i++) {
      final said = sent[i].said;

      if (said != ScanResult.valid.name || results[i].result.admits) {
        continue;
      }

      found.add(ScanConflict(
        who: sent[i].who ?? '',
        said: ScanResult.valid,
        was: results[i].result,
      ));
    }

    return found;
  }

  /// Take a copy now, because somebody pressed the button that says so.
  Future<void> _takeDoorList() async {
    setState(() => _takingList = true);

    await _loadDoorList(refresh: true);

    if (!mounted) {
      return;
    }

    setState(() => _takingList = false);

    if (_doorList == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(Strings.t('door.failed'))),
      );
    }
  }

  void _showConflicts() {
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        backgroundColor: ScannerTheme.surface,
        title: Text(Strings.t('door.conflictsTitle')),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                Strings.t('door.conflictsHint'),
                style: const TextStyle(color: ScannerTheme.muted),
              ),
              const SizedBox(height: 12),
              for (final conflict in _conflicts)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(Strings.t('door.conflictLine', {
                    'who': conflict.who.isEmpty ? Strings.t('result.valid.headline') : conflict.who,
                    'said': conflict.said.headline,
                    'was': conflict.was.headline,
                  })),
                ),
            ],
          ),
        ),
        actions: [
          FilledButton(
            style: FilledButton.styleFrom(minimumSize: const Size(96, 44)),
            onPressed: () {
              // Dismissed for good. They have been read, and a strip that will not go away is a
              // strip people stop reading.
              setState(() => _conflicts = []);
              Navigator.of(context).pop();
            },
            child: Text(Strings.t('door.dismiss')),
          ),
        ],
      ),
    );
  }

  Future<void> _handle(String code) async {
    final now = DateTime.now();

    // One code, one answer: the lens is pointed at a phone that is not moving.
    if (code == _lastCode && now.difference(_lastCodeAt) < const Duration(seconds: 3)) {
      return;
    }

    _lastCode = code;
    _lastCodeAt = now;

    if (_busy || _outcome != null) {
      return;
    }

    setState(() => _busy = true);

    final clientScanId = '${widget.event.id}:$code:${now.millisecondsSinceEpoch ~/ 1000}';

    try {
      final outcome = await widget.api.scan(
        eventId: widget.event.id,
        ticketToken: code,
        clientScanId: clientScanId,
      );

      if (!mounted) return;

      setState(() {
        _outcome = outcome;
        _online = true;
      });

      unawaited(_refreshStats());
    } on ApiFailure catch (e) {
      if (!e.isOffline) {
        // A refusal is an answer. Show it.
        if (mounted) {
          setState(() => _outcome = ScanOutcome(result: ScanResult.parse(e.code)));
        }
      } else {
        // No connection. The scan is taken either way — the person is standing there, and a door
        // that stops working when the Wi-Fi does is a door that gets propped open — but what the
        // volunteer is *told* now comes from the list this device is carrying.
        final list = _doorList;
        final verdict = list == null ? ScanOutcome.queued : list.verdict(code, _admitted);

        if (list != null && verdict.result.admits) {
          // Recorded before anything is shown: the same ticket presented again ninety seconds
          // later, still with no signal, is the easiest way there is to get two people into one
          // seat.
          await widget.doorLists.admit(DoorList.hashOf(code), now);
          _admitted = await widget.doorLists.admitted();
        }

        await widget.queue.add(PendingScan(
          clientScanId: clientScanId,
          eventId: widget.event.id,
          token: code,
          scannedAt: now,
          said: verdict.result.name,
          who: verdict.seat ?? verdict.holderName,
        ));

        await _refreshQueue();

        if (mounted) {
          setState(() {
            _online = false;
            _outcome = verdict;
          });
        }
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  void _dismiss() {
    setState(() => _outcome = null);
  }

  Future<void> _enterByHand() async {
    final controller = TextEditingController();

    final code = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        backgroundColor: ScannerTheme.surface,
        title: Text(Strings.t('scan.typeTitle')),
        content: TextField(
          controller: controller,
          autofocus: true,
          autocorrect: false,
          // A ticket code is Latin whichever way the screen reads.
          textDirection: TextDirection.ltr,
          decoration: InputDecoration(labelText: Strings.t('scan.ticketCode')),
          onSubmitted: (value) => Navigator.of(context).pop(value.trim()),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: Text(Strings.t('cancel')),
          ),
          FilledButton(
            style: FilledButton.styleFrom(minimumSize: const Size(96, 44)),
            onPressed: () => Navigator.of(context).pop(controller.text.trim()),
            child: Text(Strings.t('scan.check')),
          ),
        ],
      ),
    );

    if (code != null && code.isNotEmpty) {
      // Cleared first, so a code typed by hand is never rejected as a repeat of itself.
      _lastCode = null;
      await _handle(code);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            StatusBar(
              eventName: widget.event.name,
              online: _online,
              queued: _queued,
              onTap: widget.onChangeEvent,
            ),
            /*
             * Two strips, and neither is decoration.
             *
             * A scanner with no copy of the house cannot check anything the moment the wifi drops,
             * and the only time that can be put right is *before* it drops. A scanner that admitted
             * somebody the system then refused has a person sitting in the room who should not be,
             * and the only time anybody can act on that is tonight.
             */
            if (_doorList == null)
              _Strip(
                colour: ScannerTheme.warn,
                icon: Icons.playlist_add_rounded,
                title: Strings.t('door.none'),
                detail: Strings.t('door.noneHint'),
                action: Strings.t('door.take'),
                onAction: _takeDoorList,
                busy: _takingList,
              ),
            /*
             * The copy this scanner is armed with, and how old it is.
             *
             * One slim line, always there. "Is this thing going to work when the wifi drops" is a
             * question a duty manager asks at the door, and until now the only way to find out was
             * to switch the wifi off and try it.
             */
            if (_doorList != null)
              _Held(
                summary: Strings.t('door.held', {
                  'count': Strings.number(_doorList!.count),
                  'time': Strings.time(_doorList!.takenAt),
                }),
                busy: _takingList,
                onRefresh: _takeDoorList,
              ),
            if (_conflicts.isNotEmpty)
              _Strip(
                colour: ScannerTheme.refuse,
                icon: Icons.report_rounded,
                title: _conflicts.length == 1
                    ? Strings.t('door.conflictsOne')
                    : Strings.t('door.conflictsMany', {'count': Strings.number(_conflicts.length)}),
                detail: Strings.t('door.conflictsHint'),
                action: Strings.t('door.conflictsTitle'),
                onAction: _showConflicts,
              ),
            Expanded(
              child: Stack(
                fit: StackFit.expand,
                children: [
                  MobileScanner(
                    controller: _controller,
                    onDetect: (capture) {
                      final value = capture.barcodes
                          .map((b) => b.rawValue)
                          .firstWhere((v) => v != null && v.isNotEmpty, orElse: () => null);

                      if (value != null) {
                        _handle(value);
                      }
                    },
                    errorBuilder: (context, error) => _CameraProblem(onType: _enterByHand),
                  ),
                  const IgnorePointer(child: _Reticle()),
                  if (_stats != null)
                    // Directional rather than left: on a Persian door this belongs on the right,
                    // where the eye starts.
                    PositionedDirectional(
                      start: 16,
                      top: 16,
                      child: _Counter(stats: _stats!),
                    ),
                  if (_outcome != null)
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 0,
                      child: ResultCard(outcome: _outcome!, onDismiss: _dismiss),
                    ),
                  if (_busy && _outcome == null)
                    const Positioned(
                      left: 0,
                      right: 0,
                      bottom: 28,
                      child: Center(
                        child: SizedBox(
                          width: 30,
                          height: 30,
                          child: CircularProgressIndicator(strokeWidth: 3),
                        ),
                      ),
                    ),
                ],
              ),
            ),
            Container(
              color: ScannerTheme.surface,
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
              child: SafeArea(
                top: false,
                child: Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: _enterByHand,
                        icon: const Icon(Icons.keyboard_rounded, size: 20),
                        label: Text(Strings.t('scan.typeCode')),
                      ),
                    ),
                    const SizedBox(width: 10),
                    IconButton.filledTonal(
                      onPressed: () => _controller.toggleTorch(),
                      iconSize: 24,
                      padding: const EdgeInsets.all(14),
                      icon: const Icon(Icons.flashlight_on_rounded),
                      tooltip: Strings.t('scan.torch'),
                    ),
                    const SizedBox(width: 8),
                    IconButton.filledTonal(
                      onPressed: () => _controller.switchCamera(),
                      iconSize: 24,
                      padding: const EdgeInsets.all(14),
                      icon: const Icon(Icons.cameraswitch_rounded),
                      tooltip: Strings.t('scan.switchCamera'),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A line across the top of the door, for the two things that cannot wait until tomorrow.
class _Strip extends StatelessWidget {
  const _Strip({
    required this.colour,
    required this.icon,
    required this.title,
    required this.detail,
    required this.action,
    required this.onAction,
    this.busy = false,
  });

  final Color colour;
  final IconData icon;
  final String title;
  final String detail;
  final String action;
  final VoidCallback onAction;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: colour,
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 20, color: Colors.white),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                ),
                Text(
                  detail,
                  style: TextStyle(color: Colors.white.withValues(alpha: 0.9), fontSize: 13),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          busy
              ? const Padding(
                  padding: EdgeInsets.all(10),
                  child: SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                  ),
                )
              : TextButton(
                  style: TextButton.styleFrom(
                    foregroundColor: Colors.white,
                    minimumSize: const Size(64, 44),
                  ),
                  onPressed: onAction,
                  child: Text(action),
                ),
        ],
      ),
    );
  }
}

/// What the device is carrying, in one line.
class _Held extends StatelessWidget {
  const _Held({required this.summary, required this.onRefresh, this.busy = false});

  final String summary;
  final VoidCallback onRefresh;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: ScannerTheme.surface,
      padding: const EdgeInsetsDirectional.only(start: 14, end: 4),
      child: Row(
        children: [
          const Icon(Icons.fact_check_outlined, size: 16, color: ScannerTheme.muted),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              summary,
              style: const TextStyle(color: ScannerTheme.muted, fontSize: 12.5),
            ),
          ),
          busy
              ? const Padding(
                  padding: EdgeInsets.all(12),
                  child: SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                )
              : IconButton(
                  onPressed: onRefresh,
                  iconSize: 18,
                  visualDensity: VisualDensity.compact,
                  icon: const Icon(Icons.refresh_rounded, color: ScannerTheme.muted),
                  tooltip: Strings.t('door.retake'),
                ),
        ],
      ),
    );
  }
}

class _Reticle extends StatelessWidget {
  const _Reticle();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Container(
        width: 240,
        height: 240,
        decoration: BoxDecoration(
          border: Border.all(color: Colors.white.withValues(alpha: 0.85), width: 3),
          borderRadius: BorderRadius.circular(20),
        ),
      ),
    );
  }
}

class _Counter extends StatelessWidget {
  const _Counter({required this.stats});

  final EventStats stats;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.black.withValues(alpha: 0.55),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            Strings.t('scan.countedIn', {'count': Strings.number(stats.checkedIn)}),
            style: const TextStyle(
              color: Colors.white,
              fontSize: 20,
              fontWeight: FontWeight.w700,
            ),
          ),
          Text(
            Strings.t('scan.stillToCome', {'count': Strings.number(stats.remaining)}),
            style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 13),
          ),
        ],
      ),
    );
  }
}

/// No camera — a locked-down phone, a denied permission, a browser without one.
///
/// Typing a code is slower but it is not nothing, so the door keeps working.
class _CameraProblem extends StatelessWidget {
  const _CameraProblem({required this.onType});

  final VoidCallback onType;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: ScannerTheme.ink,
      padding: const EdgeInsets.all(28),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.no_photography_rounded, size: 40, color: ScannerTheme.muted),
            const SizedBox(height: 14),
            Text(
              Strings.t('scan.noCameraTitle'),
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 6),
            Text(
              Strings.t('scan.noCameraBody'),
              textAlign: TextAlign.center,
              style: const TextStyle(color: ScannerTheme.muted),
            ),
            const SizedBox(height: 20),
            FilledButton.icon(
              onPressed: onType,
              icon: const Icon(Icons.keyboard_rounded),
              label: Text(Strings.t('scan.typeCode')),
            ),
          ],
        ),
      ),
    );
  }
}
