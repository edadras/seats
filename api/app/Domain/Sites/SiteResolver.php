<?php

namespace App\Domain\Sites;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Turns a Host header into a site.
 *
 * This is the only way a public request selects a tenant, which is precisely why it accepts nothing
 * else: no header, no query parameter, no path segment. A request that could name its own tenant is
 * a tenant-isolation bug waiting to be found (ADR-0003 §1, threat T1).
 *
 * A miss is cached too, briefly. Without that, an unknown Host is a free database query and a bored
 * script can turn one into thousands.
 */
class SiteResolver
{
    private const HIT = 'site:host:';
    private const MISS = 'site:miss:';

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function resolve(string $host): ?Site
    {
        $hostname = SiteDomain::normalise($this->stripPort($host));

        if ('' === $hostname) {
            return null;
        }

        if (Cache::get(self::MISS.$hostname)) {
            return null;
        }

        $id = Cache::get(self::HIT.$hostname);

        if ($id) {
            $site = $this->load($id);

            if ($site) {
                return $site;
            }

            // The cache is pointing at a site that no longer resolves — a domain moved, or the
            // site was suspended between requests. Fall through and look again.
            Cache::forget(self::HIT.$hostname);
        }

        $domain = $this->tenantContext->runUnscoped(
            fn () => SiteDomain::where('hostname', $hostname)->whereNotNull('verified_at')->first()
        );

        if (! $domain) {
            Cache::put(
                self::MISS.$hostname,
                true,
                (int) config('seatmap.sites.miss_ttl_seconds', 30)
            );

            return null;
        }

        $site = $this->load($domain->site_id);

        if (! $site) {
            return null;
        }

        Cache::put(
            self::HIT.$hostname,
            $site->id,
            (int) config('seatmap.sites.resolution_ttl_seconds', 300)
        );

        return $site;
    }

    /** Called whenever a domain or a site's status changes, so routing never serves a stale answer. */
    public static function forget(string $hostname): void
    {
        $hostname = SiteDomain::normalise($hostname);

        Cache::forget(self::HIT.$hostname);
        Cache::forget(self::MISS.$hostname);
    }

    private function load(string $siteId): ?Site
    {
        return $this->tenantContext->runUnscoped(function () use ($siteId) {
            $site = Site::with(['primaryDomain', 'menus.items.page', 'menus.items.event'])->find($siteId);

            return $site && $site->isLive() ? $site : null;
        });
    }

    /**
     * A Host header carries the port, and an IPv6 literal carries colons of its own. Splitting on
     * the last colon only when it follows a `]` or there is no `]` at all keeps both cases right.
     */
    private function stripPort(string $host): string
    {
        $host = trim($host);

        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            return false === $end ? $host : substr($host, 0, $end + 1);
        }

        $colon = strrpos($host, ':');

        return false === $colon ? $host : substr($host, 0, $colon);
    }
}
