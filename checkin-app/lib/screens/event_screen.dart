import 'package:flutter/material.dart';

import '../api/models.dart';
import '../theme.dart';

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
        title: const Text('Choose an event'),
        actions: [
          if (onRefresh != null)
            IconButton(
              onPressed: onRefresh,
              icon: const Icon(Icons.refresh_rounded),
              tooltip: 'Refresh',
            ),
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
                      '$queued scan${queued == 1 ? '' : 's'} still to send',
                      style: const TextStyle(color: ScannerTheme.warn, fontSize: 13.5),
                    ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: onUnpair,
                    icon: const Icon(Icons.logout_rounded, size: 20),
                    label: const Text('Unpair this device'),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  String _when(CheckinEvent event) {
    final starts = event.startsAt?.toLocal();

    if (starts == null) {
      return event.status ?? '';
    }

    const months = [
      'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];

    final time = '${starts.hour.toString().padLeft(2, '0')}:'
        '${starts.minute.toString().padLeft(2, '0')}';

    return '${starts.day} ${months[starts.month - 1]} ${starts.year} · $time';
  }
}

class _Empty extends StatelessWidget {
  const _Empty();

  @override
  Widget build(BuildContext context) {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.event_busy_rounded, size: 40, color: ScannerTheme.muted),
            SizedBox(height: 14),
            Text(
              'This device is not allowed to scan anything yet.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 16),
            ),
            SizedBox(height: 6),
            Text(
              'Give it an event in the organiser panel, then refresh.',
              textAlign: TextAlign.center,
              style: TextStyle(color: ScannerTheme.muted),
            ),
          ],
        ),
      ),
    );
  }
}
