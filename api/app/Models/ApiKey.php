<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An API credential pair.
 *
 * The secret is returned to the tenant exactly once, in the response to the call that created it,
 * and is stored **encrypted** rather than hashed. That is not laziness: verifying an HMAC signature
 * needs the secret, so hashing would only mean signing with the hash — and a stolen database would
 * then be enough to forge requests. Encrypted with the application key, it is not (threat T5).
 *
 * Rotation issues a second key for the same client rather than replacing the first, so a site can
 * be updated without a window where neither key works.
 */
class ApiKey extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'api_client_id', 'key_id', 'secret', 'secret_hint', 'label',
        'last_used_at', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'secret' => 'encrypted',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    /**
     * @return array{model: ApiKey, secret: string} The secret is the caller's only copy.
     */
    public static function issue(ApiClient $client, ?string $label = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $secret = 'sk_'.Str::random(48);

        $key = new self([
            'tenant_id' => $client->tenant_id,
            'api_client_id' => $client->id,
            'key_id' => 'ak_'.Str::lower(Str::random(24)),
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
            'label' => $label,
            'expires_at' => $expiresAt,
        ]);
        $key->save();

        return ['model' => $key, 'secret' => $secret];
    }

    /** The key an incoming request's signature must be verified against. */
    public function signingSecret(): string
    {
        return (string) $this->secret;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
