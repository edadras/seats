<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CheckinDeviceEvent extends Pivot
{
    use BelongsToTenant, HasUuids;

    public $incrementing = false;

    protected $table = 'checkin_device_events';

    protected $keyType = 'string';

    protected $fillable = ['tenant_id', 'checkin_device_id', 'event_id'];
}
