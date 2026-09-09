import 'dart:async';

import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../api/checkin_api.dart';
import '../api/models.dart';
import '../l10n/strings.dart';
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
    required this.onChangeEvent,
  });

  final CheckinApi api;
  final CheckinEvent event;
  final ScanQueue queue;
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

  /// The last code, so a QR sitting in front of the lens is not scanned forty times a second.
  String? _lastCode;
  DateTime _lastCodeAt = DateTime.fromMillisecondsSinceEpoch(0);

  @override
  void initState() {
    super.initState();

    _refreshQueue();
    _refreshStats();

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
      await widget.api.sync(pending);
      await widget.queue.remove(pending.map((p) => p.clientScanId));

      if (mounted) {
        setState(() => _online = true);
      }

      await _refreshQueue();
      await _refreshStats();
    } on ApiFailure catch (e) {
      // Still no connection. The queue stays exactly as it is; nothing is lost by trying again.
      if (mounted && e.isOffline) {
        setState(() => _online = false);
      }
    }
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
        // No connection. Take the scan anyway: the person is standing there, and a door that
        // stops working when the Wi-Fi does is a door that gets propped open.
        await widget.queue.add(PendingScan(
          clientScanId: clientScanId,
          eventId: widget.event.id,
          token: code,
          scannedAt: now,
        ));

        await _refreshQueue();

        if (mounted) {
          setState(() {
            _online = false;
            _outcome = ScanOutcome.queued;
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
