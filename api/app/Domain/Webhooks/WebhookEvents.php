<?php

namespace App\Domain\Webhooks;

/**
 * Everything the platform will tell somebody else's server about.
 *
 * One list, in one place, for three readers: the panel's picker, the validator that refuses a
 * subscription to an event nobody publishes, and the documentation. When those three were allowed
 * to hold their own copies, the picker offered events that were never dispatched — which looks
 * exactly like a broken integration from the receiving end.
 *
 * Adding a type here does not make it happen; it has to be dispatched somewhere too. The test
 * `WebhookTest::every_published_event_is_one_somebody_can_subscribe_to` walks the source and holds
 * the two halves together, because a name that is offered and never sent is worse than one that is
 * missing: an integrator waits for it.
 */
final class WebhookEvents
{
    /**
     * The catalogue, grouped the way the picker shows it.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        'orders' => ['order.confirmed', 'order.cancelled', 'order.refunded', 'order.charged_back'],
        'events' => ['event.published', 'event.cancelled', 'event.rescheduled'],
        'door' => ['ticket.checked_in'],
        'waitlist' => ['waitlist.offered'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function has(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /**
     * The picker, named in whatever language the panel is being read in.
     *
     * @return list<array{group: string, name: string, types: list<array{type: string, name: string}>}>
     */
    public static function describe(): array
    {
        $groups = [];

        foreach (self::GROUPS as $group => $types) {
            $groups[] = [
                'group' => $group,
                'name' => __('panel.webhooks.groups.'.$group),
                'types' => array_map(fn (string $type) => [
                    'type' => $type,
                    // Keyed by the type with its dot removed, because the panel's `t()` resolves a
                    // dotted path and `order.confirmed` could never be one key.
                    'name' => __('panel.webhooks.types.'.str_replace('.', '_', $type)),
                ], $types),
            ];
        }

        return $groups;
    }
}
