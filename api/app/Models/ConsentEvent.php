<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * That somebody said yes, or said no, and how.
 *
 * Append-only: there is no update path and no delete except the one a subject access erasure takes.
 * A year later the question an auditor asks is not what the answer is but when it was given and
 * what the person was shown, and only a log answers that.
 */
class ConsentEvent extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'email', 'action', 'source', 'ip', 'note', 'recorded_by', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];
}
