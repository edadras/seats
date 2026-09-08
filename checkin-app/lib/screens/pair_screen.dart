import 'package:flutter/material.dart';

import '../api/checkin_api.dart';
import '../api/models.dart';
import '../theme.dart';

/// Pairing.
///
/// A scanner is set up once, at the start of a shift, by someone reading a code off a screen in
/// the office. So: two fields, a big button, and errors that say what to do rather than what
/// happened.
class PairScreen extends StatefulWidget {
  const PairScreen({
    super.key,
    required this.initialBaseUrl,
    required this.onPaired,
  });

  final String initialBaseUrl;
  final void Function({
    required String baseUrl,
    required String token,
    required String deviceName,
    required List<CheckinEvent> events,
  }) onPaired;

  @override
  State<PairScreen> createState() => _PairScreenState();
}

class _PairScreenState extends State<PairScreen> {
  late final _baseUrl = TextEditingController(text: widget.initialBaseUrl);
  final _code = TextEditingController();
  final _name = TextEditingController(text: 'Door scanner');

  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _baseUrl.dispose();
    _code.dispose();
    _name.dispose();
    super.dispose();
  }

  Future<void> _pair() async {
    final base = _baseUrl.text.trim();
    final code = _code.text.trim();

    if (base.isEmpty || code.isEmpty) {
      setState(() => _error = 'Fill in the address and the pairing code.');

      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final result = await CheckinApi(baseUrl: base).pair(
        pairingCode: code,
        deviceName: _name.text.trim().isEmpty ? 'Door scanner' : _name.text.trim(),
      );

      widget.onPaired(
        baseUrl: base,
        token: result.token,
        deviceName: result.deviceName,
        events: result.events,
      );
    } on ApiFailure catch (e) {
      setState(() {
        _error = e.isOffline
            ? 'Could not reach that address. Check the venue Wi-Fi and the address above.'
            : e.message;
      });
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Icon(Icons.qr_code_scanner_rounded, size: 44, color: ScannerTheme.accent),
                  const SizedBox(height: 18),
                  const Text(
                    'Pair this scanner',
                    style: TextStyle(fontSize: 28, fontWeight: FontWeight.w700, letterSpacing: -0.5),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Get a pairing code from the organiser panel. It works once, and it expires.',
                    style: TextStyle(color: ScannerTheme.muted, fontSize: 15),
                  ),
                  const SizedBox(height: 28),
                  TextField(
                    controller: _baseUrl,
                    keyboardType: TextInputType.url,
                    autocorrect: false,
                    decoration: const InputDecoration(labelText: 'Seatmap address'),
                  ),
                  const SizedBox(height: 14),
                  TextField(
                    controller: _code,
                    autocorrect: false,
                    textCapitalization: TextCapitalization.none,
                    decoration: const InputDecoration(labelText: 'Pairing code'),
                    onSubmitted: (_) => _pair(),
                  ),
                  const SizedBox(height: 14),
                  TextField(
                    controller: _name,
                    decoration: const InputDecoration(
                      labelText: 'Name this device',
                      helperText: 'Shown in the panel, so staff know which door is which.',
                      helperStyle: TextStyle(color: ScannerTheme.muted),
                    ),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 18),
                    Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: ScannerTheme.refuse.withValues(alpha: 0.14),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Icon(Icons.error_outline_rounded, color: ScannerTheme.refuse, size: 20),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              _error!,
                              style: const TextStyle(color: ScannerTheme.refuse, fontSize: 14.5),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: _busy ? null : _pair,
                    child: _busy
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                          )
                        : const Text('Pair'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
