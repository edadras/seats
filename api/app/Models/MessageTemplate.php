<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One organiser's wording for one kind of message, on one channel, in one language. */
class MessageTemplate extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'kind', 'channel', 'locale', 'subject', 'body'];
}
