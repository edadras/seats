<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The websites each organiser's seat map may be drawn on.
 *
 * The embed is three lines of HTML with no key in it, which is what makes it usable by somebody who
 * has a page and no toolchain — and also what made it copyable. View source on a venue's booking
 * page, paste the two tags anywhere, and that hall opened on a website the venue had never heard
 * of, holding real seats out of their real inventory.
 *
 * So the picker now opens where the venue said it may, and nowhere else.
 *
 * **This table is an allow-list and the default is deny**, which would break every embed already in
 * the wild — so the `up()` below does not start anybody from empty. It seeds each tenant with the
 * places we can already prove are theirs: every verified domain of their own hosted sites, and
 * every origin and site URL registered against one of their API clients. An organiser who had
 * pasted the snippet somewhere they never told us about is the one case that stops working, and
 * that is the case this exists to stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embed_origins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * Stored as a bare hostname, not as a full origin.
             *
             * A venue thinks in websites — "our WordPress at northgate.example" — and not in
             * scheme-host-port triples, and an organiser asked for an origin will type one of half
             * a dozen things that all mean the same site. The scheme is not a security boundary
             * worth enforcing here either: a venue that moves from http to https has not become a
             * different venue, and refusing them at that moment helps nobody.
             */
            $table->string('hostname');

            // What the organiser calls it, so a list of eight is a list somebody can prune.
            $table->string('label')->nullable();

            // When a page on this host last asked for a hall. It is how an organiser finds the
            // entry they can safely delete, which is the only way a list like this stays short.
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'hostname']);
        });

        $this->seedFromWhatIsAlreadyKnown();
    }

    public function down(): void
    {
        Schema::dropIfExists('embed_origins');
    }

    /**
     * Everywhere this platform can already prove a tenant owns.
     *
     * Deliberately generous: the cost of missing one is a venue whose booking page goes blank on
     * the morning of an upgrade, and the cost of an extra one is a row in a list they can delete.
     */
    private function seedFromWhatIsAlreadyKnown(): void
    {
        $found = [];

        $remember = function (?string $tenantId, ?string $value, ?string $label) use (&$found) {
            $host = $this->hostOf($value);

            if (! $tenantId || '' === $host) {
                return;
            }

            $found[$tenantId.'|'.$host] ??= [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'hostname' => $host,
                'label' => $label,
                'last_seen_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        };

        // The venue's own hosted sites. Verified only: an unverified domain is a claim, not a fact.
        DB::table('site_domains')
            ->join('sites', 'sites.id', '=', 'site_domains.site_id')
            ->whereNotNull('site_domains.verified_at')
            ->get(['sites.tenant_id', 'sites.name', 'site_domains.hostname'])
            ->each(fn ($row) => $remember($row->tenant_id, $row->hostname, $row->name));

        // And every shop that has been registered as one of theirs.
        DB::table('api_clients')->get(['tenant_id', 'name', 'site_url', 'allowed_origins'])
            ->each(function ($row) use ($remember) {
                $remember($row->tenant_id, $row->site_url, $row->name);

                foreach ((array) json_decode((string) ($row->allowed_origins ?? '[]'), true) as $origin) {
                    if (is_string($origin)) {
                        $remember($row->tenant_id, $origin, $row->name);
                    }
                }
            });

        foreach (array_chunk(array_values($found), 500) as $chunk) {
            DB::table('embed_origins')->insert($chunk);
        }
    }

    /** A hostname out of whatever shape the value happens to be in. */
    private function hostOf(?string $value): string
    {
        $value = trim((string) $value);

        if ('' === $value) {
            return '';
        }

        $host = str_contains($value, '//') ? (parse_url($value, PHP_URL_HOST) ?: '') : $value;

        return rtrim(Str::lower(trim(explode('/', $host)[0])), '.');
    }
};
