<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A shop, an agency or a bureau selling an organiser's tickets over its own counter.
 *
 * Not a promoter: a promoter posts a link and is paid a percentage of what it brings in, and never
 * touches anybody's money. An agent takes cash from the public, which is why this row carries the
 * two things a promoter's does not — the list of what they may sell, and how far they may go before
 * they have paid for it.
 */
class SalesAgent extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'contact_name', 'contact_email', 'contact_phone',
        'user_id', 'api_client_id', 'commission_rate', 'credit_limit', 'all_events', 'active', 'note',
    ];

    protected $casts = [
        'commission_rate' => 'integer',
        'credit_limit' => 'integer',
        'all_events' => 'boolean',
        'active' => 'boolean',
    ];

    public function allowances()
    {
        return $this->hasMany(SalesAgentEvent::class);
    }

    public function entries()
    {
        return $this->hasMany(AgentCreditEntry::class);
    }

    public function orders()
    {
        return $this->hasMany(ExternalOrder::class);
    }

    /** Basis points as a percentage, for anywhere a person reads it rather than multiplies by it. */
    public function commissionPercent(): float
    {
        return round($this->commission_rate / 100, 2);
    }
}
