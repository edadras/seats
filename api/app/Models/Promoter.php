<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody who sells tickets on an organiser's behalf, and what they are owed for it.
 *
 * A row an organiser created on purpose, which is the difference between this and a `utm_source`
 * anybody can type into a URL: money is paid against it.
 */
class Promoter extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'contact_email', 'commission_rate', 'active', 'note',
    ];

    protected $casts = [
        'commission_rate' => 'integer',
        'active' => 'boolean',
    ];

    /** Basis points as a percentage, for anywhere a person reads it rather than multiplies by it. */
    public function commissionPercent(): float
    {
        return round($this->commission_rate / 100, 2);
    }

    public function orders()
    {
        return $this->hasMany(ExternalOrder::class);
    }
}
