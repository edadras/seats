<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An archive of everything one account holds, and how long it lasts.
 *
 * A row rather than a file on its own, because the interesting facts about an export are not in the
 * zip: who asked for it, what went into it, and when it stops being available.
 */
class AccountExport extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'requested_by', 'status', 'path', 'bytes', 'contents', 'error',
        'ready_at', 'expires_at',
    ];

    protected $casts = [
        'contents' => 'array',
        'bytes' => 'integer',
        'ready_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isReady(): bool
    {
        return 'ready' === $this->status
            && null !== $this->path
            && (null === $this->expires_at || $this->expires_at->isFuture());
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
