<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A ticket's QR carries a random token and nothing else — no name, no order id, no signature to
 * forge. Verification is a hash lookup, so a token cannot be constructed offline (threat T6).
 */
class Ticket extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'allocation_id', 'token_hash', 'token_prefix',
        'status', 'holder_name', 'issued_at', 'used_at', 'voided_at',
    ];

    protected $hidden = ['token_hash'];

    /**
     * The plaintext QR token, present only on the instance that just issued it.
     *
     * A declared property, not an Eloquent attribute: routing it through `__set` would add it to
     * the model's attribute bag and a later save would try to write a column that does not exist.
     */
    public ?string $plainToken = null;

    protected $casts = [
        'issued_at' => 'datetime',
        'used_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function allocation()
    {
        return $this->belongsTo(Allocation::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function checkins()
    {
        return $this->hasMany(Checkin::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
