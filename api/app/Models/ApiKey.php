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
        'last_used_at', 'expires_at', 'revoked_at', 'scopes',
    ];

    /**
     * What a key can be limited to.
     *
     * Three, and they are the three answers to "what is this key for": read what was sold, sell,
     * hand money back. A shop's key needs the first two and almost never the third — the refunds
     * are done at the box office by somebody looking at the booking — and until now every key
     * could do all three because there was nothing else it could be.
     *
     * Deliberately not one scope per endpoint. A list nobody can hold in their head is a list where
     * everybody ticks everything, which is the state this replaces.
     */
    public const SCOPES = ['orders.read', 'orders.write', 'orders.refund'];

    protected $hidden = ['secret'];

    protected $casts = [
        'secret' => 'encrypted',
        'scopes' => 'array',
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
    public static function issue(
        ApiClient $client,
        ?string $label = null,
        ?\DateTimeInterface $expiresAt = null,
        ?array $scopes = null,
    ): array {
        $secret = 'sk_'.Str::random(48);

        $key = new self([
            'tenant_id' => $client->tenant_id,
            'api_client_id' => $client->id,
            'key_id' => 'ak_'.Str::lower(Str::random(24)),
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
            'label' => $label,
            'expires_at' => $expiresAt,
            // Null rather than the whole list when nobody chose: "everything" and "everything,
            // written out" read the same today and differently the day a scope is added.
            'scopes' => self::tidyScopes($scopes),
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

    /**
     * Whether this key may do one particular thing.
     *
     * Null means everything, which is what every key issued before scopes existed could already do.
     * A migration that silently narrowed live keys would take a working shop off sale at the moment
     * it was deployed, and the shop would have no idea why.
     */
    public function allows(string $scope): bool
    {
        if (! is_array($this->scopes)) {
            return true;
        }

        return in_array($scope, $this->scopes, true);
    }

    /**
     * The list as it should be stored: known scopes only, in a fixed order, or null for everything.
     *
     * Ordered so that two keys with the same powers read the same on the screen, and narrowed to
     * the catalogue so that a scope somebody invents cannot sit in the column looking as though it
     * grants something.
     *
     * @param  array<int, mixed>|null  $wanted
     * @return list<string>|null
     */
    public static function tidyScopes(?array $wanted): ?array
    {
        if (null === $wanted) {
            return null;
        }

        $kept = array_values(array_intersect(self::SCOPES, array_map(
            fn ($scope) => is_string($scope) ? $scope : '',
            $wanted,
        )));

        return $kept;
    }
}
