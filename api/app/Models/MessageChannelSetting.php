<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Whether one kind of message goes out on one channel, for this organiser. */
class MessageChannelSetting extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'kind', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
