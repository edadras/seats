<?php

namespace App\Jobs;

use App\Domain\Sites\SiteResolver;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Proves that whoever added a hostname controls it, before we serve anything at it.
 *
 * DNS pointing at us proves nothing about ownership — anyone can point a record anywhere. Without
 * this, an organiser could claim a name they do not own and receive its visitors on our
 * certificate, which is the difference between "your own domain" and "any domain" (ADR-0003 §2).
 */
class VerifySiteDomain implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $domainId) {}

    public function handle(TenantContext $tenants): void
    {
        // Unscoped by necessity: a queued job has no request and therefore no tenant, and this one
        // acts on a single row it was handed by id rather than searching across tenants.
        $domain = $tenants->runUnscoped(fn () => SiteDomain::find($this->domainId));

        if (! $domain || $domain->isVerified()) {
            return;
        }

        $record = $domain->expectedRecord();
        $values = $this->lookup($record['name']);

        $found = in_array($domain->verification_token, $values, true);

        $tenants->runUnscoped(function () use ($domain, $found) {
            $domain->forceFill([
                'last_checked_at' => now(),
                'verified_at' => $found ? now() : null,
                'last_error' => $found ? null : 'The TXT record was not found, or does not match yet.',
            ])->save();
        });

        if ($found) {
            // The routing cache holds misses too, so a freshly verified hostname would otherwise
            // keep 404ing for as long as that entry lives.
            SiteResolver::forget($domain->hostname);
        }
    }

    /**
     * @return array<int, string>
     */
    private function lookup(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            // A long TXT value arrives split into chunks; some resolvers give the pieces and some
            // give the joined string, so take whichever is present.
            if (isset($record['entries']) && is_array($record['entries'])) {
                $values[] = implode('', $record['entries']);
            }

            if (isset($record['txt'])) {
                $values[] = (string) $record['txt'];
            }
        }

        return array_map('trim', $values);
    }
}
