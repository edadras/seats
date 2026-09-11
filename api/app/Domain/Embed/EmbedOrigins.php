<?php

namespace App\Domain\Embed;

use App\Models\EmbedOrigin;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Which websites an organiser's seat map may be drawn on.
 *
 * The embed carries no key, on purpose: it is three lines of HTML for somebody who has a page and
 * no toolchain, and a key sitting in a page's source is not a key. What that bought in ease it paid
 * for in copyability — view source on a venue's booking page, paste the two tags anywhere, and that
 * hall opened on a website the venue had never heard of, holding seats out of their real inventory.
 *
 * So the question this answers is not "who is calling" — nobody can prove that from a browser —
 * but "is the page this is being drawn on one the venue named". That is a weaker question, and it
 * is the honest one:
 *
 *   **`Origin` is a fact about a browser, not a proof about a person.** A browser sets it and will
 *   not let a page lie about it, which is exactly what stops the copy-and-paste this exists to
 *   stop. Anything that is not a browser — curl, a server-side proxy, a scraper — can send whatever
 *   it likes, and this does not pretend otherwise. What is behind the embed API is a public
 *   programme and a chart the venue already shows to the world, so the thing being protected is the
 *   venue's brand and their inventory's rate limits, not a secret.
 *
 *   **Deny is the default, and the list is seeded rather than started empty.** An allow-list that
 *   begins empty is an allow-list that takes every existing embed offline on the morning it ships.
 *   The migration seeds each tenant from what the platform can already prove is theirs — verified
 *   hosted-site domains, registered API client origins — so the only thing that stops working is
 *   the thing this was built to stop.
 */
class EmbedOrigins
{
    /** Long enough to be worth having on an on-sale, short enough that adding a site feels instant. */
    private const TTL_SECONDS = 60;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Whether a page on this origin may draw this tenant's halls.
     *
     * **A stated origin is enforced. A missing one is not invented into a refusal**, and that
     * asymmetry is the whole design rather than a concession:
     *
     *   The thing being stopped is a copied snippet, and a copied snippet runs in a browser. A
     *   browser always sends `Origin` on a cross-origin `fetch` and will not let the page change
     *   it — so every instance of the threat arrives here *with* an origin, and refusing the wrong
     *   ones catches all of them.
     *
     *   A request with no origin at all is not a browser making a cross-origin call. It is a
     *   script, a server-side proxy, an integration. Anything in that position can set
     *   `Origin: https://the-venue.test` in one line and become indistinguishable from the venue's
     *   own page — so refusing the empty case stops only somebody who has built a proxy and then
     *   not bothered, while breaking every honest caller that is not a browser. It would be a
     *   guard that reads as strict and buys nothing.
     *
     * The line where that stops being acceptable is the line this platform already draws: nothing
     * secret is behind these endpoints. Every route that guards something authenticates, and none
     * of them relies on this.
     */
    public function allows(string $tenantId, ?string $origin): bool
    {
        if (null === $origin || '' === trim($origin)) {
            return true;
        }

        $host = EmbedOrigin::normalise($origin);

        if ('' === $host) {
            return false;
        }

        $allowed = $this->hostsFor($tenantId);

        return in_array($host, $allowed, true)
            || in_array(EmbedOrigin::apex($host), $allowed, true);
    }

    /**
     * The hostnames on a tenant's list, `www`-folded so either spelling matches either entry.
     *
     * @return list<string>
     */
    public function hostsFor(string $tenantId): array
    {
        return Cache::remember(self::cacheKey($tenantId), self::TTL_SECONDS, function () use ($tenantId) {
            $rows = EmbedOrigin::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->pluck('hostname')
                ->all();

            $hosts = [];

            foreach ($rows as $hostname) {
                $hosts[] = $hostname;
                $hosts[] = EmbedOrigin::apex($hostname);
            }

            return array_values(array_unique($hosts));
        });
    }

    /**
     * Note that a page on this origin asked for a hall today.
     *
     * Written at most once a day per site rather than on every request: this is how an organiser
     * finds the entry nobody uses any more, which is the only way a list like this stays short
     * enough to be read — and an on-sale must not turn that into a write per seat map fetched.
     */
    public function seen(string $tenantId, ?string $origin): void
    {
        $host = EmbedOrigin::normalise((string) $origin);

        if ('' === $host) {
            return;
        }

        $marker = 'embed:seen:'.$tenantId.':'.$host;

        if (Cache::get($marker)) {
            return;
        }

        Cache::put($marker, true, now()->addDay());

        EmbedOrigin::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('hostname', array_unique([$host, EmbedOrigin::apex($host), 'www.'.EmbedOrigin::apex($host)]))
            ->update(['last_seen_at' => now()]);
    }

    /** @return list<array{id:string, hostname:string, label:?string, last_seen_at:?string}> */
    public function all(): array
    {
        return EmbedOrigin::orderBy('hostname')->get()->map(fn (EmbedOrigin $origin) => [
            'id' => $origin->id,
            'hostname' => $origin->hostname,
            'label' => $origin->label,
            'last_seen_at' => $origin->last_seen_at?->toIso8601String(),
        ])->values()->all();
    }

    public function add(string $value, ?string $label): EmbedOrigin
    {
        $hostname = EmbedOrigin::normalise($value);

        if ('' === $hostname) {
            throw \App\Exceptions\ApiException::unprocessable(
                'embed_origin_invalid',
                __('errors.embed_origin_invalid'),
            );
        }

        $origin = EmbedOrigin::firstOrNew(['hostname' => $hostname]);
        $origin->label = $label ?: $origin->label;
        $origin->save();

        $this->forget($this->tenants->idOrFail());

        return $origin;
    }

    public function remove(EmbedOrigin $origin): void
    {
        $tenantId = $origin->tenant_id;

        $origin->delete();

        $this->forget($tenantId);
    }

    public function forget(string $tenantId): void
    {
        Cache::forget(self::cacheKey($tenantId));
    }

    private static function cacheKey(string $tenantId): string
    {
        return 'embed:origins:'.$tenantId;
    }
}
