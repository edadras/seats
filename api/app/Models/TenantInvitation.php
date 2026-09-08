<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An invitation to join an organiser.
 *
 * The token exists in plaintext exactly once — on the response that created the invitation, so it
 * can be put in an email — and is stored hashed. An invitation link is a way into an account, and a
 * leaked database should not be a set of working ones.
 */
class TenantInvitation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    /** Present only on the instance that just issued it. */
    public ?string $plainToken = null;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        // Long enough that guessing is not a strategy, and URL-safe so it survives an email client.
        return Str::random(48);
    }

    public function isPending(): bool
    {
        return null === $this->accepted_at && $this->expires_at->isFuture();
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
