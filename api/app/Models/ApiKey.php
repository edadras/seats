<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An API credential pair. The secret exists in plaintext exactly once — in the response to the
 * call that created it. After that only `secret_hash` remains, so a database compromise does not
 * hand over the ability to confirm sales.
 *
 * Rotation issues a second key for the same client rather than replacing the first, so a site can
 * be updated without a window where neither key works.
 */
class ApiKey extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'api_client_id', 'key_id', 'secret_hash', 'label',
        'last_used_at', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['secret_hash'];

    protected $casts = [
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
            'secret_hash' => hash('sha256', $secret),
            'label' => $label,
            'expires_at' => $expiresAt,
        ]);
        $key->save();

        return ['model' => $key, 'secret' => $secret];
    }

    public function matches(string $secret): bool
    {
        return hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
