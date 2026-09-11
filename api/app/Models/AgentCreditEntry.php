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

    /*
     * Four movements, and the difference between the two that go out matters.
     *
     * A settlement is the agency handing over what it has taken — money that moved, in the
     * organiser's direction. A deduction is the organiser taking credit back: a returned float, a
     * penalty, a correction of somebody's own typo. Both make the balance smaller and they are not
     * the same event, and a statement that called them one thing would be a statement nobody could
     * reconcile against a bank.
     */
    public const KINDS = ['topup', 'settlement', 'deduction', 'adjustment'];

    protected $fillable = [
        'tenant_id', 'sales_agent_id', 'kind', 'amount', 'currency',
        'method', 'reference', 'note', 'recorded_by',
    ];

    protected $casts = ['amount' => 'integer'];
}
