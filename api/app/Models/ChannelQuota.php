<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * How much of one night a channel may sell.
 *
 * A promise an organiser made to somebody: an agent gets four hundred, the website gets the rest,
 * and neither can take the other's. The absence of a row is the ordinary case — no limit at all.
 *
 * The model holds the promise and nothing else. What a channel has actually taken is a sum against
 * this limit and cannot be read off a column — see App\Domain\Channels\ChannelQuotas::taken.
 */
class ChannelQuota extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'event_id', 'api_client_id', 'places', 'note'];

    protected $casts = ['places' => 'integer'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function client()
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }
}
