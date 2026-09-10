<?php

namespace App\Domain\Attribution;

use App\Models\ExternalOrder;
use App\Models\Promoter;
use Illuminate\Http\Request;

/**
 * Which link a buyer arrived on, and what that turns out to be worth.
 *
 * **Last touch, inside a window.** A buyer who clicks a promoter's post in March and an
 * advertisement in April is attributed to the advertisement: it is the thing that finally worked,
 * and it is the answer every organiser expects when they ask "where did this sale come from". The
 * window exists so that a link clicked in January does not claim a booking made in June; it is
 * configuration rather than a constant, because thirty days is a convention and not a fact.
 *
 * **Stamped once, then left alone.** What was clicked is a fact about that afternoon. A promoter
 * later renamed, deactivated or given a different percentage must not rewrite what happened, so
 * the code, the name and the rate on the day are copied onto the booking rather than joined to.
 *
 * **What is owed is worked out, never stored.** Commission follows the tickets: it falls when a
 * booking is refunded, and a number written down at checkout would not.
 */
class Attribution
{
    /** The query parameters worth remembering, and the one that means money. */
    private const CAMPAIGN_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    public const SESSION_KEY = 'seatmap_attribution';

    /**
     * Read a landing off the request, if there is anything on it worth keeping.
     *
     * @return array<string, string>|null
     */
    public function fromRequest(Request $request): ?array
    {
        $landing = [];

        foreach (self::CAMPAIGN_KEYS as $key) {
            $value = $request->query($key);

            if (is_string($value) && '' !== trim($value)) {
                $landing[$key] = mb_substr(trim($value), 0, 120);
            }
        }

        $code = $request->query('p');

        if (is_string($code) && '' !== trim($code)) {
            $landing['code'] = mb_substr(trim($code), 0, 40);
        }

        if ([] === $landing) {
            return null;
        }

        // Where they came from, as a hostname rather than a whole address: the referring page can
        // carry somebody's search terms, and that is not ours to keep.
        $referrer = (string) $request->headers->get('referer', '');
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

        if (is_string($host) && '' !== $host) {
            $landing['referrer'] = mb_substr($host, 0, 120);
        }

        return $landing + ['at' => now()->toIso8601String()];
    }

    /** Is a remembered landing still recent enough to have sold anything? */
    public function stillCounts(?array $landing): bool
    {
        if (! $landing || ! ($landing['at'] ?? null)) {
            return false;
        }

        $days = max(1, (int) config('seatmap.attribution.window_days'));

        return \Illuminate\Support\Carbon::parse($landing['at'])->greaterThan(now()->subDays($days));
    }

    /** The promoter a remembered landing names, if they are one of this account's and still active. */
    public function promoterFor(?array $landing): ?Promoter
    {
        if (! $this->stillCounts($landing) || ! ($landing['code'] ?? null)) {
            return null;
        }

        return Promoter::where('code', $landing['code'])->where('active', true)->first();
    }

    /**
     * Stamp a booking with where it came from.
     *
     * On a first registration only. A retried submit lands on the same order, and an order that
     * already says where it came from must not be told again by a session that has since seen a
     * different link.
     */
    public function stamp(ExternalOrder $order, ?array $landing): void
    {
        if (null !== $order->attribution || ! $this->stillCounts($landing)) {
            return;
        }

        $promoter = $this->promoterFor($landing);

        $order->forceFill([
            'promoter_id' => $promoter?->id,
            'attribution' => array_filter([
                'code' => $landing['code'] ?? null,
                // Copied rather than joined: what they were called and what they were owed, on the
                // day. Renaming somebody must not rewrite last month.
                'promoter' => $promoter?->name,
                'commission_rate' => $promoter?->commission_rate,
                'utm_source' => $landing['utm_source'] ?? null,
                'utm_medium' => $landing['utm_medium'] ?? null,
                'utm_campaign' => $landing['utm_campaign'] ?? null,
                'utm_content' => $landing['utm_content'] ?? null,
                'utm_term' => $landing['utm_term'] ?? null,
                'referrer' => $landing['referrer'] ?? null,
                'at' => $landing['at'] ?? null,
            ], fn ($value) => null !== $value && '' !== $value),
        ])->save();
    }

    /**
     * What a promoter is owed for one booking.
     *
     * The tickets only. A booking fee is the platform's work and the VAT is the state's; paying a
     * percentage of either would be paying somebody for something they did not sell.
     */
    public function commissionOn(ExternalOrder $order): int
    {
        $rate = (int) ($order->attribution['commission_rate'] ?? 0);

        if ($rate < 1 || ! in_array($order->status, ['confirmed', 'partially_refunded'], true)) {
            return 0;
        }

        $tickets = (int) $order->allocations()->where('status', 'active')->sum('amount');

        return (int) round(($tickets * $rate) / 10000);
    }
}
