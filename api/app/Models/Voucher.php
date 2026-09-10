<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Money this organiser owes somebody: a gift voucher, or an account's credit.
 *
 * The model answers who may spend it and whether it is still spendable at all. It does not answer
 * "how much is left": that is the sum of the movements, and reading a balance off a column is how
 * two browsers spend the same voucher in the same second — see App\Domain\Vouchers\Vouchers.
 */
class Voucher extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /** Bearer, or named. The database enforces that exactly one identifying column is filled. */
    public const KINDS = ['gift', 'credit'];

    protected $fillable = [
        'tenant_id', 'kind', 'code', 'email', 'amount', 'currency', 'note',
        'recipient', 'expires_at', 'status', 'created_by', 'bought_with_order_id',
    ];

    protected $casts = [
        'amount' => 'integer',
        'expires_at' => 'datetime',
    ];

    /** What a buyer typed, turned into the one spelling this table stores. */
    public static function normalise(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * A code somebody can read off a printed card.
     *
     * The same alphabet as an access code — no O against 0, no I against 1 — and longer, because
     * this one is worth money and a short bearer code is a code worth guessing. Grouped in fours
     * because a person reading it aloud pauses there whether the code invites them to or not.
     */
    public static function suggest(int $groups = 4): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $parts = [];

        for ($group = 0; $group < $groups; $group++) {
            $code = '';

            for ($i = 0; $i < 4; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $parts[] = $code;
        }

        return implode('-', $parts);
    }

    public function movements()
    {
        return $this->hasMany(VoucherMovement::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScope('tenant');
    }

    /** The booking that bought it, where somebody bought it rather than being given it. */
    public function boughtWith()
    {
        return $this->belongsTo(ExternalOrder::class, 'bought_with_order_id');
    }

    public function isGift(): bool
    {
        return 'gift' === $this->kind;
    }

    /** Not voided, and not out of date. Whether there is anything left is a different question. */
    public function isLive(?\DateTimeInterface $at = null): bool
    {
        $at = $at ?: now();

        return 'active' === $this->status
            && ! ($this->expires_at && $this->expires_at->lessThanOrEqualTo($at));
    }

    /**
     * How it is shown back to the person holding it.
     *
     * A gift voucher is named by its code; credit has none, and is named by the address it belongs
     * to. Never print a full code where the whole code is the credential — but this is shown to
     * somebody who already typed it, so it is shown whole.
     */
    public function label(): string
    {
        return $this->isGift() ? (string) $this->code : (string) $this->email;
    }
}
