<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One invoice, frozen at the moment it was issued.
 *
 * Everything a reader sees is in the row: both parties, every line and every amount. Nothing is
 * looked up again, because a document somebody has filed with their accounts must not change when
 * the organiser corrects their address.
 */
class Invoice extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'site_id', 'external_order_row_id', 'number', 'year', 'sequence',
        'issued_at', 'currency', 'issuer', 'buyer', 'totals', 'lines',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'year' => 'integer',
        'sequence' => 'integer',
        'issuer' => 'array',
        'buyer' => 'array',
        'totals' => 'array',
        'lines' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
