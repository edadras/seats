import 'package:flutter/material.dart';

import '../api/checkin_api.dart';
import '../api/models.dart';
import '../l10n/strings.dart';
import '../theme.dart';
import '../widgets/language_button.dart';

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
  final _name = TextEditingController(text: Strings.t('defaultDeviceName'));

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
      setState(() => _error = Strings.t('pair.incomplete'));

      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final result = await CheckinApi(baseUrl: base).pair(
        pairingCode: code,
        deviceName: _name.text.trim().isEmpty
            ? Strings.t('defaultDeviceName')
            : _name.text.trim(),
      );

      widget.onPaired(
        baseUrl: base,
        token: result.token,
        deviceName: result.deviceName,
        events: result.events,
      );
    } on ApiFailure catch (e) {
      setState(() {
        _error = e.isOffline ? Strings.t('pair.unreachable') : e.message;
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
                  // The language menu comes before the fields, because a volunteer who cannot read
                  // the labels needs it before, not after.
                  const Align(
                    alignment: AlignmentDirectional.centerEnd,
                    child: LanguageButton(),
                  ),
                  const SizedBox(height: 10),
                  const Icon(Icons.qr_code_scanner_rounded, size: 44, color: ScannerTheme.accent),
                  const SizedBox(height: 18),
                  Text(
                    Strings.t('pair.title'),
                    style: const TextStyle(
                      fontSize: 28,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -0.5,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    Strings.t('pair.subtitle'),
                    style: const TextStyle(color: ScannerTheme.muted, fontSize: 15),
                  ),
                  const SizedBox(height: 28),
                  TextField(
                    controller: _baseUrl,
                    keyboardType: TextInputType.url,
                    autocorrect: false,
                    // An address is never right-to-left, whichever way the screen reads.
                    textDirection: TextDirection.ltr,
                    decoration: InputDecoration(labelText: Strings.t('pair.address')),
                  ),
                  const SizedBox(height: 14),
                  TextField(
                    controller: _code,
                    autocorrect: false,
                    textCapitalization: TextCapitalization.none,
                    textDirection: TextDirection.ltr,
                    decoration: InputDecoration(labelText: Strings.t('pair.code')),
                    onSubmitted: (_) => _pair(),
                  ),
                  const SizedBox(height: 14),
                  TextField(
                    controller: _name,
                    decoration: InputDecoration(
                      labelText: Strings.t('pair.deviceName'),
                      helperText: Strings.t('pair.deviceNameHint'),
                      helperStyle: const TextStyle(color: ScannerTheme.muted),
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
                        : Text(Strings.t('pair.submit')),
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
