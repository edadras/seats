<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Money that actually passed between an organiser and an agent.
 *
 * Only that. What the agent has sold, refunded and earned in commission is counted from the
 * allocations every time somebody asks, so a refunded ticket returns its credit without anything
 * having to remember to — and no two columns can drift apart.
 */
class AgentCreditEntry extends Model
{
    use BelongsToTenant, HasUuids;

    /** Money in from the agent, money out to them, and anything somebody signed their name to. */
    public const KINDS = ['topup', 'settlement', 'adjustment'];

    protected $fillable = [
        'tenant_id', 'sales_agent_id', 'kind', 'amount', 'currency',
        'method', 'reference', 'note', 'recorded_by',
    ];

    protected $casts = ['amount' => 'integer'];
}
