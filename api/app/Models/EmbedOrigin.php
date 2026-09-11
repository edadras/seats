<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One website an organiser's seat map may be drawn on.
 */
class EmbedOrigin extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'hostname', 'label', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    /**
     * A hostname out of whatever an organiser typed.
     *
     * They will paste `https://www.northgate.test/tickets?x=1`, or type `Northgate.test `, or copy
     * an origin out of a console message. All of those mean one website, and a form that accepts
     * only one spelling of it is a form that teaches people the feature is broken.
     *
     * A port is kept where there is one: `localhost:8200` and `localhost:3000` are genuinely
     * different sites to a browser, and a developer testing an embed needs to say which.
     */
    public static function normalise(string $value): string
    {
        $value = trim($value);

        if ('' === $value) {
            return '';
        }

        if (str_contains($value, '//')) {
            $parts = parse_url($value);
            $host = (string) ($parts['host'] ?? '');
            $port = $parts['port'] ?? null;
        } else {
            [$host] = explode('/', $value, 2);
            $port = null;

            // `host:port` typed without a scheme. A bare `northgate.test` has no colon, and an
            // IPv6 address is not something anybody embeds a ticket shop on.
            if (substr_count($host, ':') === 1) {
                [$host, $typed] = explode(':', $host, 2);
                $port = ctype_digit($typed) ? (int) $typed : null;
            }
        }

        $host = rtrim(Str::lower(trim($host)), '.');

        /*
         * A default port is not part of an origin.
         *
         * A browser sends `Origin: https://northgate.test` and never `https://northgate.test:443`,
         * so an organiser who pastes a URL with the port spelled out would register something no
         * request can ever match — and would be left looking at a list that says the site is
         * allowed while the site is refused.
         */
        $scheme = Str::lower((string) parse_url($value, PHP_URL_SCHEME));

        if ((443 === $port && 'https' === $scheme) || (80 === $port && 'http' === $scheme)) {
            $port = null;
        }

        return '' === $host ? '' : $host.($port ? ':'.$port : '');
    }

    /**
     * `www` is not a different website.
     *
     * It is to a browser, which is why this exists: whoever controls `northgate.test` controls
     * `www.northgate.test`, so treating them as one costs nothing and saves an organiser from a
     * blank booking page whose cause is a prefix they did not think to mention.
     */
    public static function apex(string $hostname): string
    {
        return Str::startsWith($hostname, 'www.') ? Str::substr($hostname, 4) : $hostname;
    }
}
