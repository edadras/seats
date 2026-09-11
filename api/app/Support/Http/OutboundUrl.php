<?php

namespace App\Support\Http;

use App\Exceptions\ApiException;

/**
 * Whether the server may be told to fetch this.
 *
 * Two places take a URL from an organiser and then make the *server* open it: a webhook endpoint,
 * and an identity provider's discovery document. A server making a request on somebody else's
 * instruction is a server that can be pointed inwards — at a metadata service on a link-local
 * address, at a database on the private network, at something listening only on localhost — and the
 * reply comes back to the person who asked for it. That is the whole of SSRF, and nothing in this
 * codebase was checking for it.
 *
 * So: https only, a name rather than an address, and every address that name resolves to has to be
 * on the public internet.
 *
 * What this does not close is DNS rebinding — a name that answers publicly here and privately when
 * the request is actually made a moment later. Closing that means resolving once and connecting to
 * the address, which the HTTP client does not offer without giving up TLS verification of the name.
 * The operator's egress rules are the place that is closed properly; this is the part the
 * application can do honestly.
 */
final class OutboundUrl
{
    /** Ranges nothing an organiser types may reach. */
    private const FORBIDDEN_V4 = [
        ['0.0.0.0', 8],          // this network
        ['10.0.0.0', 8],         // private
        ['100.64.0.0', 10],      // carrier-grade NAT
        ['127.0.0.0', 8],        // loopback
        ['169.254.0.0', 16],     // link-local: the cloud metadata service lives here
        ['172.16.0.0', 12],      // private
        ['192.0.0.0', 24],       // IETF protocol assignments
        ['192.168.0.0', 16],     // private
        ['198.18.0.0', 15],      // benchmarking
        ['224.0.0.0', 4],        // multicast
        ['240.0.0.0', 4],        // reserved
    ];

    /**
     * Why this URL may not be fetched, or null if it may.
     *
     * A code rather than a sentence: the caller turns it into whichever refusal it raises, and the
     * six catalogues hold the words.
     */
    public static function refuse(string $url, bool $allowPlainHttp = false): ?string
    {
        $parts = parse_url(trim($url));
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ('' === $host || ! in_array($scheme, $allowPlainHttp ? ['http', 'https'] : ['https'], true)) {
            return 'url_not_https';
        }

        // An address typed directly is refused even when it is public: there is no legitimate
        // integration that cannot be given a name, and it removes a whole class of near-miss.
        // Checked before anything is resolved, so it costs no lookup and holds in every environment.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return 'url_not_a_name';
        }

        /*
         * Resolving is the half that needs the network.
         *
         * Left on everywhere it matters and turned off in the test suite, where the receivers are
         * invented names that resolve to nothing — a guard that refused every fixture would be
         * turned off by whoever met it next. The classifier it feeds is tested directly instead,
         * against the whole table of addresses, which is where the actual judgement lives.
         */
        if (! config('seatmap.webhooks.verify_destination', true)) {
            return null;
        }

        $addresses = self::resolve($host);

        if ([] === $addresses) {
            return 'url_unresolvable';
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                return 'url_private';
            }
        }

        return null;
    }

    /**
     * The same judgement, as a refusal.
     *
     * Here rather than at each call site so that the four codes are written once and literally —
     * the catalogue check reads the source for them, and a `throw` whose code is a variable is a
     * code nothing can prove has a sentence in six languages.
     */
    public static function assert(string $url, bool $allowPlainHttp = false): void
    {
        $refusal = self::refuse($url, $allowPlainHttp);

        if (null === $refusal) {
            return;
        }

        throw match ($refusal) {
            'url_not_https' => ApiException::unprocessable(
                'url_not_https',
                'That address has to start with https://.'
            ),
            'url_not_a_name' => ApiException::unprocessable(
                'url_not_a_name',
                'Give the address a hostname rather than an IP address.'
            ),
            'url_unresolvable' => ApiException::unprocessable(
                'url_unresolvable',
                'That hostname does not resolve to anything.'
            ),
            default => ApiException::unprocessable(
                'url_private',
                'That address is on a private network, so this server will not open it.'
            ),
        };
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        // Both families: a name with only a AAAA record pointing at ::1 is the same attack.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $addresses = [];

        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (! empty($record[$key])) {
                    $addresses[] = (string) $record[$key];
                }
            }
        }

        return $addresses;
    }

    /** Whether one resolved address is somewhere on the public internet. */
    public static function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // ::, ::1, fc00::/7 (unique local), fe80::/10 (link-local), and anything mapped back
            // into IPv4 space, which is how a v4 loopback sneaks past a v6 check.
            $packed = @inet_pton($address);

            if (false === $packed) {
                return false;
            }

            if (in_array($address, ['::', '::1'], true)) {
                return false;
            }

            $first = ord($packed[0]);

            if (0xFC === ($first & 0xFE) || (0xFE === $first && 0x80 === (ord($packed[1]) & 0xC0))) {
                return false;
            }

            // ::ffff:a.b.c.d — judge it as the v4 address it actually is.
            if (str_starts_with(bin2hex($packed), str_repeat('0', 20).'ffff')) {
                return self::isPublicAddress(inet_ntop(substr($packed, 12)));
            }

            return true;
        }

        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $value = ip2long($address);

        foreach (self::FORBIDDEN_V4 as [$base, $bits]) {
            $mask = -1 << (32 - $bits);

            if ((ip2long($base) & $mask) === ($value & $mask)) {
                return false;
            }
        }

        return true;
    }
}
