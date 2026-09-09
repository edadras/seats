<?php

namespace App\Domain\Notifications;

/**
 * The things the platform tells an organiser about, and who may hear each one.
 *
 * A closed list for the same reason the message kinds are one: every entry is a sentence somebody
 * has to be able to read in six languages, and a notification system that accepts arbitrary text
 * from anywhere is a notification system whose Persian is somebody's English.
 *
 * `permission` is checked when the list is read rather than when the row is written, because roles
 * change: a volunteer promoted to manager on Tuesday should see Monday's refunds, and somebody who
 * loses the box office should stop seeing them.
 *
 * `level` is how loud it is. `danger` also goes out by email to the people who hold the permission,
 * because something a buyer did not receive is not a thing to find out next time somebody opens
 * the panel.
 */
class NotificationKinds
{
    public const KINDS = [
        'order.refunded' => ['permission' => 'orders.view', 'level' => 'warn'],
        'event.sold_out' => ['permission' => 'events.view', 'level' => 'info'],
        'announcement.finished' => ['permission' => 'messages.send', 'level' => 'info'],
        'message.refused' => ['permission' => 'account.manage', 'level' => 'danger'],
        'domain.verified' => ['permission' => 'sites.view', 'level' => 'info'],
    ];

    public static function exists(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }

    public static function permission(string $kind): ?string
    {
        return self::KINDS[$kind]['permission'] ?? null;
    }

    public static function level(string $kind): string
    {
        return self::KINDS[$kind]['level'] ?? 'info';
    }

    /** The translation key for one half of a notification's sentence. */
    public static function key(string $kind, string $part): string
    {
        return 'notifications.kinds.'.str_replace('.', '_', $kind).'.'.$part;
    }
}
