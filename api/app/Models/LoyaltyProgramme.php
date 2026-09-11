<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The rules points are read under: what earns them, what they are worth, and the ladder.
 *
 * One per account, and in one currency. A rate of "a point per euro" says nothing in an account
 * that also sells in rials, and a single pool fed by two currencies is arithmetic nobody can
 * explain to a customer.
 */
class LoyaltyProgramme extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'enabled', 'currency', 'earn_rate',
        'points_per_unit', 'min_redeem', 'tiers', 'window_months', 'inactive_months',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'tiers' => 'array',
        'earn_rate' => 'integer',
        'points_per_unit' => 'integer',
        'min_redeem' => 'integer',
        'window_months' => 'integer',
        'inactive_months' => 'integer',
    ];

    /**
     * The ladder, lowest first and every rung well formed.
     *
     * Sorted on the way out rather than trusted on the way in: the order decides which tier
     * somebody is in, and a list saved out of order by a screen that meant no harm would quietly
     * hand everybody the wrong standing.
     *
     * @return list<array{key:string, name:string, from_points:int}>
     */
    public function ladder(): array
    {
        $rungs = [];

        foreach ((array) ($this->tiers ?? []) as $tier) {
            if (! is_array($tier) || ! isset($tier['key'], $tier['from_points'])) {
                continue;
            }

            $rungs[] = [
                'key' => (string) $tier['key'],
                'name' => (string) ($tier['name'] ?? $tier['key']),
                'from_points' => max(0, (int) $tier['from_points']),
            ];
        }

        usort($rungs, fn ($a, $b) => $a['from_points'] <=> $b['from_points']);

        return $rungs;
    }

    /** Whether this programme is actually doing anything. */
    public function isLive(): bool
    {
        return $this->enabled && $this->earn_rate > 0;
    }
}
