<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'address', 'city', 'country', 'timezone', 'metadata'];

    protected $casts = ['metadata' => 'array'];

    public function seatMaps()
    {
        return $this->hasMany(SeatMap::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }
}
