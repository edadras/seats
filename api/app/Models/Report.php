<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved report definition — never a saved result (ADR-0006 §2).
 *
 * It is re-run when it is read, so it cannot go stale and cannot become a copy of numbers that
 * have since been corrected.
 */
class Report extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'source_key', 'definition', 'created_by'];

    protected $casts = ['definition' => 'array'];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScope('tenant');
    }
}
