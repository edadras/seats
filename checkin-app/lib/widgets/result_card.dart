import 'package:flutter/material.dart';

import '../api/models.dart';
import '../l10n/strings.dart';
import '../theme.dart';

/// The answer, as big as it can be.
///
/// A door scanner is read in a second by someone who is also talking to a person. So: one word,
/// one colour, one icon, and the seat underneath for the cases where staff have to point.
class ResultCard extends StatelessWidget {
  const ResultCard({super.key, required this.outcome, required this.onDismiss});

  final ScanOutcome outcome;
  final VoidCallback onDismiss;

  Color get _colour => switch (outcome.result) {
        ScanResult.valid => ScannerTheme.admit,
        ScanResult.alreadyUsed => ScannerTheme.warn,
        ScanResult.queued => ScannerTheme.accent,
        _ => ScannerTheme.refuse,
      };

  IconData get _icon => switch (outcome.result) {
        ScanResult.valid => Icons.check_circle_rounded,
        ScanResult.alreadyUsed => Icons.history_rounded,
        ScanResult.queued => Icons.cloud_off_rounded,
        ScanResult.wrongEvent => Icons.event_busy_rounded,
        _ => Icons.cancel_rounded,
      };

  @override
  Widget build(BuildContext context) {
    return Semantics(
      liveRegion: true,
      label: '${outcome.result.headline}. ${outcome.result.detail}',
      child: Container(
        width: double.infinity,
        color: _colour,
        padding: const EdgeInsets.fromLTRB(24, 32, 24, 24),
        child: SafeArea(
          top: false,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(_icon, size: 56, color: Colors.white),
              const SizedBox(height: 12),
              Text(
                outcome.result.headline,
                style: const TextStyle(
                  fontSize: 40,
                  height: 1.05,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                  letterSpacing: -0.5,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                outcome.result.detail,
                style: TextStyle(fontSize: 16, color: Colors.white.withValues(alpha: 0.92)),
              ),
              if (outcome.seat != null || outcome.holderName != null) ...[
                const SizedBox(height: 16),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: Colors.black.withValues(alpha: 0.18),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (outcome.seat != null)
                        Text(
                          outcome.seat!,
                          style: const TextStyle(
                            fontSize: 20,
                            fontWeight: FontWeight.w600,
                            color: Colors.white,
                          ),
                        ),
                      if (outcome.holderName != null)
                        Text(
                          outcome.holderName!,
                          style: TextStyle(fontSize: 15, color: Colors.white.withValues(alpha: 0.9)),
                        ),
                      // When, and by which door, is what settles an argument at the entrance.
                      if (outcome.firstScan != null)
                        Text(
                          outcome.firstScanBy != null
                              ? Strings.t('scan.firstScannedBy', {
                                  'time': Strings.time(outcome.firstScan!),
                                  'by': outcome.firstScanBy,
                                })
                              : Strings.t('scan.firstScanned', {
                                  'time': Strings.time(outcome.firstScan!),
                                }),
                          style: TextStyle(fontSize: 14, color: Colors.white.withValues(alpha: 0.85)),
                        ),
                    ],
                  ),
                ),
              ],
              const SizedBox(height: 20),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  style: FilledButton.styleFrom(
                    backgroundColor: Colors.white,
                    foregroundColor: _colour,
                  ),
                  onPressed: onDismiss,
                  child: Text(Strings.t('scan.next')),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
