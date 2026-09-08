<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeatMap extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'venue_id', 'name', 'description', 'published_version_id'];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function versions()
    {
        return $this->hasMany(SeatMapVersion::class)->orderByDesc('version');
    }

    public function publishedVersion()
    {
        return $this->belongsTo(SeatMapVersion::class, 'published_version_id');
    }

    public function sections()
    {
        return $this->hasMany(Section::class);
    }

    public function seats()
    {
        return $this->hasMany(Seat::class);
    }

    public function capacityObjects()
    {
        return $this->hasMany(CapacityObject::class);
    }

    /** The version currently open for editing, if any. */
    public function draftVersion(): ?SeatMapVersion
    {
        return $this->versions()->where('status', 'draft')->orderByDesc('version')->first();
    }
}
