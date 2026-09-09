<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Something the platform is telling this account.
 *
 * The row holds a kind and its facts, never a sentence: the wording is translated when it is read,
 * in the reader's own language. A notification written in one language and read in another is the
 * thing ADR-0005 exists to prevent.
 */
class Notification extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'kind', 'level', 'params', 'subject_type', 'subject_id', 'subject_label',
    ];

    protected $casts = ['params' => 'array'];

    public function reads()
    {
        return $this->hasMany(NotificationRead::class);
    }
}
