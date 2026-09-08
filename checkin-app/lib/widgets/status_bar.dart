import 'package:flutter/material.dart';

import '../theme.dart';

/// The one line that says whether this device is any use right now: which event, whether it can
/// reach the server, and how many scans are waiting to be sent.
class StatusBar extends StatelessWidget {
  const StatusBar({
    super.key,
    required this.eventName,
    required this.online,
    required this.queued,
    this.onTap,
  });

  final String eventName;
  final bool online;
  final int queued;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ScannerTheme.surface,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Row(
            children: [
              Container(
                width: 10,
                height: 10,
                decoration: BoxDecoration(
                  // Never colour alone: the words next to it say the same thing.
                  color: online ? ScannerTheme.admit : ScannerTheme.warn,
                  shape: BoxShape.circle,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      eventName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
                    ),
                    Text(
                      online ? 'Online' : 'Offline — scans are being saved',
                      style: const TextStyle(color: ScannerTheme.muted, fontSize: 12.5),
                    ),
                  ],
                ),
              ),
              if (queued > 0)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  decoration: BoxDecoration(
                    color: ScannerTheme.warn.withValues(alpha: 0.18),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Text(
                    '$queued waiting',
                    style: const TextStyle(
                      color: ScannerTheme.warn,
                      fontWeight: FontWeight.w600,
                      fontSize: 12.5,
                    ),
                  ),
                ),
              if (onTap != null) ...[
                const SizedBox(width: 6),
                const Icon(Icons.chevron_right, color: ScannerTheme.muted, size: 20),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
