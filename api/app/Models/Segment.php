<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A named question about buyers: "came last season, has not bought this one".
 *
 * It holds rules and no people — see the migration for why, and App\Domain\Audience\Segments for
 * what the rules may say.
 */
class Segment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'description', 'rules', 'created_by'];

    protected $casts = ['rules' => 'array'];

    public function announcements()
    {
        return $this->hasMany(Announcement::class);
    }
}
