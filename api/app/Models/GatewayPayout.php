<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One transfer, as the card processor states it.
 *
 * Testimony rather than arithmetic: nothing on this row is derived from anything in this database,
 * which is the whole point of it. It is the one number in the system that did not come from here.
 */
class GatewayPayout extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'gateway', 'reference', 'currency', 'paid_on',
        'gross', 'fees', 'net', 'note', 'created_by',
    ];

    protected $casts = [
        'paid_on' => 'date',
        'gross' => 'integer',
        'fees' => 'integer',
        'net' => 'integer',
    ];

    public function lines()
    {
        return $this->hasMany(GatewayPayoutLine::class);
    }
}
