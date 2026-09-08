<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Checkin extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'ticket_id', 'checkin_device_id', 'checkin_operator_id',
        'result', 'scanned_at', 'client_scan_id', 'offline',
    ];

    protected $casts = ['scanned_at' => 'datetime', 'offline' => 'boolean'];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function device()
    {
        return $this->belongsTo(CheckinDevice::class, 'checkin_device_id');
    }

    public function operator()
    {
        return $this->belongsTo(CheckinOperator::class, 'checkin_operator_id');
    }
}
