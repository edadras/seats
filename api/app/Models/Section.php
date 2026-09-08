<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'seat_map_id', 'key', 'name', 'color', 'sort_order'];

    public function rows()
    {
        return $this->hasMany(SeatRow::class);
    }
}
