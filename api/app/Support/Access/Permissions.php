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
        'orders.refund' => 'boxoffice',

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
            'events.view', 'events.manage', 'events.publish', 'pricing.manage',
            'venues.view', 'venues.manage', 'maps.view', 'maps.manage', 'maps.publish',
            'tickets.view', 'tickets.release', 'orders.view', 'orders.refund',
            'checkins.view', 'devices.manage',
            'sites.view', 'sites.manage', 'sites.publish', 'domains.manage',
            'reports.attendance.view', 'reports.orders.view', 'reports.build',
            'messages.send',
            'team.view', 'team.manage', 'roles.manage', 'modules.manage', 'connections.manage',
            'audit.view', 'account.manage',
        ],
        'manager' => [
            'events.view', 'events.manage', 'events.publish', 'pricing.manage',
            'venues.view', 'venues.manage', 'maps.view', 'maps.manage', 'maps.publish',
            'tickets.view', 'tickets.release', 'orders.view', 'orders.refund',
            'checkins.view', 'devices.manage',
            'sites.view', 'sites.manage', 'sites.publish',
            'reports.attendance.view', 'reports.orders.view', 'reports.build',
            'messages.send',
            'team.view',
        ],
        'box_office' => [
            'events.view', 'venues.view', 'maps.view',
            'tickets.view', 'tickets.release', 'orders.view', 'orders.refund',
            'checkins.view',
            'reports.attendance.view', 'reports.orders.view',
        ],
        'door' => [
            'events.view',
            'tickets.view',
            'checkins.view', 'devices.manage',
            'reports.attendance.view',
        ],
        'viewer' => [
            'events.view', 'venues.view', 'maps.view', 'sites.view',
            'reports.attendance.view',
        ],
    ];

    /** The keys nobody may take for a custom role. */
    public const RESERVED_ROLE_KEYS = ['owner', 'admin', 'manager', 'box_office', 'door', 'viewer'];

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
