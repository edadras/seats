<?php

namespace App\Support\Access;

/**
 * Every permission the platform has, and the roles that come with it.
 *
 * A closed list, checked at the point of use. `canWrite()` — one boolean for every mutation in the
 * system — was the thing this replaces, and its problem was not that it was coarse but that it was
 * *undiscussable*: there was no way to give somebody the box office without also giving them the
 * seat maps, the site, and the API keys.
 *
 * The separation that matters most here is money from operations. A volunteer coordinator needs to
 * know who has come in; they do not need to know what the evening took. `checkins.view` and
 * `orders.view` are different permissions for that reason and must stay different.
 */
final class Permissions
{
    /**
     * @var array<string, string> permission => the group it is shown under in the panel
     */
    public const ALL = [
        // --- Programme -----------------------------------------------------------------
        'events.view' => 'programme',
        'events.manage' => 'programme',
        'events.publish' => 'programme',
        'pricing.manage' => 'programme',
        // Giving money away is a pricing decision, so it sits with pricing rather than with the
        // box office: finding a booking and inventing a half-price code are different jobs.
        'discounts.manage' => 'programme',

        // --- Venue and seating ---------------------------------------------------------
        'venues.view' => 'seating',
        'venues.manage' => 'seating',
        'maps.view' => 'seating',
        'maps.manage' => 'seating',
        'maps.publish' => 'seating',

        // --- Box office ----------------------------------------------------------------
        'tickets.view' => 'boxoffice',
        'tickets.release' => 'boxoffice',
        'orders.view' => 'boxoffice',
        /*
         * The same lookup, narrowed to what the holder sold themselves.
         *
         * Its own permission rather than a filter inside `orders.view`, because the two are
         * different authorities and a filter applied *after* a permission check is a filter
         * somebody eventually forgets. `orders.view` reads the house — and the house includes the
         * customer directory, the waiting list and every abandoned basket, all of which are screens
         * an outside agency must never be handed. This one reads their own book and nothing else.
         */
        'orders.view.own' => 'boxoffice',
        'orders.refund' => 'boxoffice',
        // Selling at the window, and giving a seat away. Separate from refunding because they are
        // separate jobs: a volunteer on the door can be trusted to hand out comps for tonight
        // without also being able to move money back onto somebody's card.
        'orders.sell' => 'boxoffice',
        /*
         * Issuing a voucher, and writing one off.
         *
         * Beside refunding rather than beside discounts, because it is the same act with the same
         * weight: moving the organiser's money to a buyer. A voucher issued against no payment is
         * money given away, so every issue is audited — but the person who takes a refund request
         * over the telephone is the person who should be able to settle it as credit, and making
         * them ask a manager would push the whole thing back onto a spreadsheet.
         */
        'vouchers.manage' => 'boxoffice',
        /*
         * The shops and bureaux that sell on the organiser's behalf, and their accounts.
         *
         * Beside the box office rather than under the account, because it is the same kind of act:
         * deciding what somebody may sell and how far they may go before they have paid for it.
         * Separate from `orders.sell` for the reason every one of these is separate — the person
         * who works the window is not thereby the person who extends an agency eleven thousand
         * euros of credit.
         */
        'agents.manage' => 'boxoffice',

        // --- The door ------------------------------------------------------------------
        'checkins.view' => 'door',
        'devices.manage' => 'door',

        // --- Websites ------------------------------------------------------------------
        'sites.view' => 'sites',
        'sites.manage' => 'sites',
        'sites.publish' => 'sites',
        'domains.manage' => 'sites',

        // --- Reports -------------------------------------------------------------------
        // Split by what they reveal, not by which screen shows them (ADR-0006 §5).
        'reports.attendance.view' => 'reports',
        'reports.orders.view' => 'reports',
        // Building and saving reports is separate from reading them: what a report may show is
        // still decided by its source's permission, so this widens nobody's view (ADR-0006 §5).
        'reports.build' => 'reports',

        // --- Messages ------------------------------------------------------------------
        // Writing to everybody who bought is a different act from configuring the platform, so it
        // is a different permission: a manager who runs the programme can say "tonight is moved"
        // without also being able to change the account's settings.
        'messages.send' => 'account',

        // --- Account -------------------------------------------------------------------
        'team.view' => 'account',
        'team.manage' => 'account',
        'roles.manage' => 'account',
        'modules.manage' => 'account',
        'connections.manage' => 'account',
        'audit.view' => 'account',
        'billing.manage' => 'account',
        'account.manage' => 'account',
    ];

    /**
     * The roles every organiser gets without inventing any.
     *
     * `owner` is not in this table: it holds everything, always, and expressing that as a list is
     * how an account ends up locked out of itself when a permission is added.
     *
     * These are jobs people actually have. `box_office` can find a booking, refund it and put a
     * seat back on sale, and cannot touch the seat map it belongs to. `door` can see who came in
     * and pair a scanner, and cannot see what the evening took.
     */
    public const ROLES = [
        'admin' => [
            'events.view', 'events.manage', 'events.publish', 'pricing.manage', 'discounts.manage',
            'venues.view', 'venues.manage', 'maps.view', 'maps.manage', 'maps.publish',
            'tickets.view', 'tickets.release', 'orders.view', 'orders.refund', 'orders.sell',
            'vouchers.manage', 'agents.manage',
            'checkins.view', 'devices.manage',
            'sites.view', 'sites.manage', 'sites.publish', 'domains.manage',
            'reports.attendance.view', 'reports.orders.view', 'reports.build',
            'messages.send',
            'team.view', 'team.manage', 'roles.manage', 'modules.manage', 'connections.manage',
            'audit.view', 'account.manage',
        ],
        'manager' => [
            'events.view', 'events.manage', 'events.publish', 'pricing.manage', 'discounts.manage',
            'venues.view', 'venues.manage', 'maps.view', 'maps.manage', 'maps.publish',
            'tickets.view', 'tickets.release', 'orders.view', 'orders.refund', 'orders.sell',
            'vouchers.manage', 'agents.manage',
            'checkins.view', 'devices.manage',
            'sites.view', 'sites.manage', 'sites.publish',
            'reports.attendance.view', 'reports.orders.view', 'reports.build',
            'messages.send',
            'team.view',
        ],
        'box_office' => [
            'events.view', 'venues.view', 'maps.view',
            'tickets.view', 'tickets.release', 'orders.view', 'orders.refund', 'orders.sell',
            'vouchers.manage',
            'checkins.view',
            'reports.attendance.view', 'reports.orders.view',
        ],
        'door' => [
            'events.view',
            'tickets.view',
            'checkins.view', 'devices.manage',
            'reports.attendance.view',
        ],
        /*
         * Somebody who sells for the organiser without working for them.
         *
         * Deliberately thin, and thinner than the box office in the places that matter: no refunds,
         * because money back is the organiser's decision; no vouchers, because that is the
         * organiser's money; and `orders.view.own` rather than `orders.view`, because the wide one
         * also opens the customer directory, the waiting list and the abandoned baskets — the
         * organiser's audience, which is not what they sold an agency. No `tickets.view` either:
         * the house's attendee list is the organiser's, and an agency reprints from its own
         * bookings. What an agent may sell is not in this list at all — it is the list of events
         * they were given, checked at the moment of sale.
         */
        'agent' => [
            'events.view',
            'venues.view',
            'maps.view',
            'orders.view.own',
            'orders.sell',
        ],

        'viewer' => [
            'events.view', 'venues.view', 'maps.view', 'sites.view',
            'reports.attendance.view',
        ],
    ];

    /** The keys nobody may take for a custom role. */
    public const RESERVED_ROLE_KEYS = ['owner', 'admin', 'manager', 'box_office', 'door', 'agent', 'viewer'];

    public static function exists(string $permission): bool
    {
        return isset(self::ALL[$permission]);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    /** @return list<string> */
    public static function group(string $group): array
    {
        return array_keys(array_filter(self::ALL, fn (string $g) => $g === $group));
    }

    /** @return list<string> the groups, in the order the panel shows them */
    public static function groups(): array
    {
        return array_values(array_unique(array_values(self::ALL)));
    }

    /** Drop anything that is not a real permission. Input, not configuration. */
    public static function sanitise(array $permissions): array
    {
        return array_values(array_unique(array_filter(
            $permissions,
            fn ($permission) => is_string($permission) && self::exists($permission)
        )));
    }

    /**
     * What a built-in role holds. Unknown roles hold nothing rather than everything: a typo in a
     * role name must lock somebody out, never let them in.
     *
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        if ('owner' === $role) {
            return self::keys();
        }

        return self::ROLES[$role] ?? [];
    }

    public static function isBuiltIn(string $role): bool
    {
        return 'owner' === $role || isset(self::ROLES[$role]);
    }
}
