<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's current answer to "may we write to you about things you have not bought?".
 *
 * The record is `consent_events`; this is that log folded, so a segment over forty thousand buyers
 * is one join rather than forty thousand folds. It is rebuilt from the log rather than trusted —
 * see App\Domain\Privacy\Consents::rebuild.
 */
class MarketingConsent extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'email', 'state', 'source', 'ip', 'note', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function isIn(): bool
    {
        return 'in' === $this->state;
    }
}
