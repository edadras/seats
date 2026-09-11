<?php

namespace App\Domain\Sites;

use App\Models\Site;

/**
 * What a hosted site is allowed to measure, and with what.
 *
 * Three providers, each named by an id in a shape narrow enough to be certain about. Deliberately
 * **not** a box to paste a snippet into: organiser-supplied markup on a domain we serve — and a
 * checkout we run the card form on — is a stored cross-site scripting hole that crosses tenants,
 * and "it is only for their analytics" is exactly how such a box gets added. The `video` block made
 * this decision first; this is the same one.
 *
 * The script tags are built here from the provider and the id, so nothing an organiser typed is
 * ever interpolated into a `src`.
 */
class Measurement
{
    /**
     * The id each provider is identified by, and what one looks like.
     *
     * Google's measurement id is `G-` and an alphanumeric tail; Meta's pixel is a long number;
     * Plausible is keyed by the domain the site is registered under there. Anything else is not
     * that provider's id, whatever it is.
     */
    public const PROVIDERS = [
        'ga4' => '/^G-[A-Z0-9]{4,20}$/',
        'meta' => '/^[0-9]{6,20}$/',
        'plausible' => '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',
    ];

    /**
     * Normalise what the panel sent.
     *
     * An id that does not match its provider's shape is dropped rather than stored, so a site can
     * never be left with a tag that does nothing and an organiser wondering why no data arrives.
     *
     * @return array<string, string>
     */
    public static function clean(mixed $given): array
    {
        if (! is_array($given)) {
            return [];
        }

        $clean = [];

        foreach (self::PROVIDERS as $provider => $shape) {
            $id = trim((string) (is_scalar($given[$provider] ?? null) ? $given[$provider] : ''));

            // Case is part of neither Google's nor Plausible's id in practice, and fixing it here
            // saves an organiser a support ticket about a pasted id that "does not work".
            $id = 'ga4' === $provider ? mb_strtoupper($id) : mb_strtolower($id);

            if ('' !== $id && preg_match($shape, $id)) {
                $clean[$provider] = $id;
            }
        }

        return $clean;
    }

    /**
     * What a page needs in order to load these, once the visitor has said yes.
     *
     * Returns the script address and the id separately rather than a blob of markup: the template
     * escapes, and the addresses here are constants.
     *
     * @return list<array{provider: string, id: string, src: ?string}>
     */
    public static function forSite(Site $site): array
    {
        $out = [];

        foreach (self::clean($site->measurement ?? []) as $provider => $id) {
            $out[] = [
                'provider' => $provider,
                'id' => $id,
                'src' => match ($provider) {
                    'ga4' => 'https://www.googletagmanager.com/gtag/js?id='.$id,
                    'meta' => 'https://connect.facebook.net/en_US/fbevents.js',
                    // The script-only build: it takes the site from the address it is loaded on,
                    // and the outbound-link and file-download extensions are not switched on for
                    // somebody else's visitors by us.
                    'plausible' => 'https://plausible.io/js/script.js',
                    default => null,
                },
            ];
        }

        return $out;
    }

    /** Whether this site measures anything at all — and therefore whether it has to ask. */
    public static function measures(Site $site): bool
    {
        return [] !== self::clean($site->measurement ?? []);
    }
}
