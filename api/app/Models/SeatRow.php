<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeatRow extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'seat_rows';

    protected $fillable = ['tenant_id', 'seat_map_id', 'section_id', 'key', 'name', 'sort_order'];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function seats()
    {
        return $this->hasMany(Seat::class, 'seat_row_id');
    }
}
