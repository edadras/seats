<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A code somebody has to type to prove the address is theirs.
 *
 * Stored hashed, capped at six attempts, and valid for a day. Six digits is short enough to read
 * out of an email and type; the cap is what stops six digits from being guessable.
 */
class EmailVerification extends Model
{
    use HasUuids;

    public const MAX_ATTEMPTS = 6;

    protected $fillable = ['user_id', 'code_hash', 'attempts', 'expires_at', 'sent_at'];

    protected $casts = ['expires_at' => 'datetime', 'sent_at' => 'datetime', 'attempts' => 'integer'];

    public static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    /** Replace whatever code that user had: a new one always invalidates the old. */
    public static function issueFor(User $user): array
    {
        static::where('user_id', $user->id)->delete();

        $code = static::newCode();

        $record = static::create([
            'user_id' => $user->id,
            'code_hash' => static::hash($code),
            'expires_at' => now()->addDay(),
            'sent_at' => now(),
        ]);

        return [$record, $code];
    }

    public function isUsable(): bool
    {
        return $this->expires_at->isFuture() && $this->attempts < self::MAX_ATTEMPTS;
    }

    public function matches(string $code): bool
    {
        return hash_equals($this->code_hash, static::hash(Str::of($code)->trim()->toString()));
    }
}
