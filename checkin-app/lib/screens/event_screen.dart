import 'package:flutter/material.dart';

import '../api/models.dart';
import '../l10n/strings.dart';
import '../theme.dart';
import '../widgets/language_button.dart';

/// Which door am I on?
///
/// A device is authorised for a set of events, and picking the wrong one is how a scanner tells
/// two hundred people they are at the wrong performance. So the list shows the date plainly and
/// the choice is remembered.
class EventScreen extends StatelessWidget {
  const EventScreen({
    super.key,
    required this.events,
    required this.selectedId,
    required this.onSelect,
    required this.deviceName,
    required this.queued,
    required this.onUnpair,
    this.onRefresh,
  });

  final List<CheckinEvent> events;
  final String? selectedId;
  final ValueChanged<CheckinEvent> onSelect;
  final String deviceName;
  final int queued;
  final VoidCallback onUnpair;
  final Future<void> Function()? onRefresh;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(Strings.t('events.title')),
        actions: [
          if (onRefresh != null)
            IconButton(
              onPressed: onRefresh,
              icon: const Icon(Icons.refresh_rounded),
              tooltip: Strings.t('events.refresh'),
            ),
          const LanguageButton(compact: true),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: events.isEmpty
                ? const _Empty()
                : ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: events.length,
                    separatorBuilder: (context, index) => const SizedBox(height: 10),
                    itemBuilder: (context, index) {
                      final event = events[index];
                      final selected = event.id == selectedId;

                      return Material(
                        color: ScannerTheme.surface,
                        borderRadius: BorderRadius.circular(14),
                        child: InkWell(
                          borderRadius: BorderRadius.circular(14),
                          onTap: () => onSelect(event),
                          child: Container(
                            padding: const EdgeInsets.all(16),
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(14),
                              border: Border.all(
                                color: selected ? ScannerTheme.accent : ScannerTheme.border,
                                width: selected ? 2 : 1,
                              ),
                            ),
                            child: Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        event.name,
                                        style: const TextStyle(
                                          fontSize: 17,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                      const SizedBox(height: 3),
                                      Text(
                                        _when(event),
                                        style: const TextStyle(
                                          color: ScannerTheme.muted,
                                          fontSize: 14,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                Icon(
                                  selected ? Icons.check_circle_rounded : Icons.chevron_right_rounded,
                                  color: selected ? ScannerTheme.accent : ScannerTheme.muted,
                                ),
                              ],
                            ),
                          ),
                        ),
                      );
                    },
                  ),
          ),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(16),
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: ScannerTheme.border)),
            ),
            child: SafeArea(
              top: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    deviceName,
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                  if (queued > 0)
                    Text(
                      queued == 1
                          ? Strings.t('events.queuedOne')
                          : Strings.t('events.queuedMany', {'count': Strings.number(queued)}),
                      style: const TextStyle(color: ScannerTheme.warn, fontSize: 13.5),
                    ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: onUnpair,
                    icon: const Icon(Icons.logout_rounded, size: 20),
                    label: Text(Strings.t('events.unpair')),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// The date, in this reader's calendar. Picking the wrong performance is how a scanner tells two
  /// hundred people they are at the wrong show, and a date somebody has to convert first is a date
  /// that gets picked wrong.
  String _when(CheckinEvent event) {
    final starts = event.startsAt;

    return starts == null ? event.status ?? '' : Strings.dateTime(starts);
  }
}

class _Empty extends StatelessWidget {
  const _Empty();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.event_busy_rounded, size: 40, color: ScannerTheme.muted),
            const SizedBox(height: 14),
            Text(
              Strings.t('events.emptyTitle'),
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 16),
            ),
            const SizedBox(height: 6),
            Text(
              Strings.t('events.emptyBody'),
              textAlign: TextAlign.center,
              style: const TextStyle(color: ScannerTheme.muted),
            ),
          ],
        ),
      ),
    );
  }
}
