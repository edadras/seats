<?php

namespace App\Domain\Sites\Auth;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Signing a buyer in with Google.
 *
 * The shape of this is decided by one fact: organisers' sites live on their own domains, and a
 * redirect URI has to be registered in a Google project before Google will send anybody to it.
 * Registering every customer's domain is not possible, and asking each organiser to create their
 * own Google Cloud project means nobody turns it on. So the round trip happens on the platform's
 * own host — one redirect URI, registered once — and the buyer is handed back to their site with a
 * one-time token.
 *
 * Two things travel through that round trip, and neither is trusted on the way back:
 *
 *   - **state** is a random nonce, and what it means is kept here rather than in the parameter. A
 *     `state` that says which site to return to is a `state` an attacker can write.
 *   - **the handoff** is a second random token, good for sixty seconds and exactly one use, that
 *     names the site and the address Google verified. The site's own domain trades it for a
 *     session; nothing about the buyer travels in the URL.
 *
 * An address Google has not verified is refused. `email_verified` false means Google knows the
 * account claims that address and has not checked it, and this platform matches orders by address:
 * accepting one would be handing somebody else's tickets to whoever claimed their email.
 */
class GoogleIdentity
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const STATE = 'buyer:google:state:';

    private const HANDOFF = 'buyer:google:handoff:';

    public const STATE_TTL = 600;

    public const HANDOFF_TTL = 60;

    /** Whether the platform has been given credentials at all. */
    public function configured(): bool
    {
        return '' !== $this->clientId() && '' !== $this->clientSecret();
    }

    /**
     * Where to send the buyer, and the nonce that will bring them back to this site.
     *
     * The return path is stored here, not sent to Google: it is a path on the organiser's site,
     * and a URL that came back from a third party is a URL somebody else can choose.
     */
    public function beginUrl(Site $site, string $returnPath): string
    {
        $nonce = Str::random(48);

        Cache::put(self::STATE.$nonce, [
            'site_id' => $site->id,
            'return' => $this->safePath($returnPath),
        ], self::STATE_TTL);

        return self::AUTH.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $nonce,
            // The buyer is choosing which account bought the tickets, so ask every time rather
            // than silently reusing whichever Google session the browser happens to hold.
            'prompt' => 'select_account',
        ]);
    }

    /** @return array{site_id:string, return:string}|null */
    public function claimState(string $nonce): ?array
    {
        $state = Cache::pull(self::STATE.$nonce);

        return is_array($state) ? $state : null;
    }

    /**
     * Trade the code for the person.
     *
     * @return array{email:string, name:?string}|null null when Google refuses, or when the
     *                                                 address it returns is not a verified one
     */
    public function identify(string $code): ?array
    {
        $token = Http::asForm()->timeout(12)->post(self::TOKEN, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (! $token->successful() || ! $token->json('access_token')) {
            return null;
        }

        $person = Http::withToken((string) $token->json('access_token'))
            ->timeout(12)
            ->get(self::USERINFO);

        if (! $person->successful()) {
            return null;
        }

        $email = trim(mb_strtolower((string) $person->json('email')));
        $verified = $person->json('email_verified');

        if ('' === $email || true !== $verified && 'true' !== $verified) {
            return null;
        }

        return [
            'email' => $email,
            'name' => $this->cleanName($person->json('name')),
        ];
    }

    /** A token the site's own domain can trade for a session, once, within a minute. */
    public function handOff(Site $site, array $person): string
    {
        $token = Str::random(64);

        Cache::put(self::HANDOFF.$token, [
            'site_id' => $site->id,
            'email' => $person['email'],
            'name' => $person['name'],
        ], self::HANDOFF_TTL);

        return $token;
    }

    /** @return array{email:string, name:?string}|null */
    public function claimHandoff(Site $site, string $token): ?array
    {
        $handoff = Cache::get(self::HANDOFF.$token);

        // Read, then check, then spend — in that order. Pulling first would let any other site on
        // the platform burn a token by asking for it, which is somebody else's sign-in failing for
        // no reason they could ever work out.
        if (! is_array($handoff) || ($handoff['site_id'] ?? null) !== $site->id) {
            return null;
        }

        Cache::forget(self::HANDOFF.$token);

        return ['email' => $handoff['email'], 'name' => $handoff['name'] ?? null];
    }

    /**
     * A path on this site, and nothing else.
     *
     * "Where should I send you afterwards" is the classic open redirect: `//evil.example` and
     * `https://evil.example` are both perfectly good values for a browser and neither is a page
     * on the organiser's site.
     */
    public function safePath(?string $path): string
    {
        $path = trim((string) $path);

        if ('' === $path || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/account';
        }

        return $path;
    }

    public function redirectUri(): string
    {
        $configured = (string) config('seatmap.signin.google.redirect', '');

        return '' !== $configured
            ? $configured
            : rtrim((string) config('app.url'), '/').'/auth/google/callback';
    }

    private function cleanName(mixed $name): ?string
    {
        $name = trim((string) $name);

        // Shown back to the buyer on their own page, and never to anybody else. Length-capped
        // because it arrives from a third party and ends up in a session.
        return '' === $name ? null : mb_substr($name, 0, 120);
    }

    private function clientId(): string
    {
        return trim((string) config('seatmap.signin.google.client_id', ''));
    }

    private function clientSecret(): string
    {
        return trim((string) config('seatmap.signin.google.client_secret', ''));
    }
}
