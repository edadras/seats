<?php

namespace App\Domain\Auth;

use App\Exceptions\ApiException;
use App\Models\IdentityProvider;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Signing in with the account an organisation already governs.
 *
 * A national theatre with a hundred staff does not want a hundred passwords on somebody else's
 * platform. It wants the directory it already runs — where people are joined on their first day and
 * removed on their last — to be the thing that decides who gets in here.
 *
 * The flow is ordinary OpenID Connect, and the one design decision worth stating is what it does
 * *not* do: it never parses an identity token. The code is exchanged on the back channel, over TLS,
 * with the client secret, and the person is read from the issuer's own userinfo endpoint. A token
 * that arrived down a connection we authenticated to the issuer needs no signature check by us, and
 * the alternative — verifying RS256 against a key set by hand — is a cryptographic implementation
 * this platform would then own for ever. {@see \App\Domain\Sites\Auth\GoogleIdentity} made the same
 * choice for buyers, for the same reason.
 *
 * Two rules shape the rest:
 *
 *   1. **Nobody is created by signing in.** An address the provider vouches for gets in only if
 *      somebody at this venue already invited it. Otherwise a directory of forty thousand students
 *      would be forty thousand people with a box office login.
 *   2. **The way back in is never a password.** When single sign-on is required, a password is not
 *      a way in at all — including for the owner. An account that locks itself out is unlocked by
 *      the platform, because a break-glass password is precisely what an attacker goes looking for.
 */
class SingleSignOn
{
    private const STATE = 'sso:state:';

    private const HANDOFF = 'sso:handoff:';

    /** How long somebody has to finish at their provider before the attempt is stale. */
    public const STATE_TTL = 600;

    /** How long the one-time handle is worth anything once they are back. */
    public const HANDOFF_TTL = 60;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /** The provider for one account, whatever tenant is in context. */
    public function forTenant(Tenant|string $tenant): ?IdentityProvider
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->tenants->runUnscoped(
            fn () => IdentityProvider::withoutGlobalScopes()->where('tenant_id', $id)->first()
        );
    }

    /**
     * Ask the issuer where its endpoints are.
     *
     * Done when the settings are saved rather than on every sign-in, and refused loudly when the
     * address is not an OpenID Connect issuer — an organiser looking at the screen can fix a typo,
     * and the same typo found at half past seven on a Friday by somebody trying to sign in cannot
     * be fixed by them at all.
     *
     * @return array{authorize_url:string, token_url:string, userinfo_url:string}
     */
    public function discover(string $issuer): array
    {
        $url = rtrim(trim($issuer), '/').'/.well-known/openid-configuration';

        try {
            $response = Http::timeout(12)->acceptJson()->get($url);
        } catch (\Throwable $e) {
            report($e);

            throw ApiException::unprocessable(
                'issuer_unreachable',
                'That address did not answer. Check it and try again.'
            );
        }

        if (! $response->successful()) {
            throw ApiException::unprocessable(
                'issuer_unreachable',
                'That address did not answer. Check it and try again.'
            );
        }

        $found = [
            'authorize_url' => (string) $response->json('authorization_endpoint'),
            'token_url' => (string) $response->json('token_endpoint'),
            'userinfo_url' => (string) $response->json('userinfo_endpoint'),
        ];

        foreach ($found as $endpoint) {
            if ('' === $endpoint || ! str_starts_with($endpoint, 'https://')) {
                throw ApiException::unprocessable(
                    'issuer_not_openid',
                    'That address answered, but it is not an OpenID Connect provider.'
                );
            }
        }

        return $found;
    }

    /**
     * Where to send somebody, and the handle that will bring them back.
     *
     * The state is kept here rather than carried, because everything that comes back from the
     * provider is somebody else's to choose. It is spent on return, so a link that is followed
     * twice works once.
     */
    public function beginUrl(Tenant $tenant, IdentityProvider $provider): string
    {
        $state = Str::random(48);
        $verifier = Str::random(64);

        Cache::put(self::STATE.$state, [
            'tenant_id' => $tenant->id,
            'verifier' => $verifier,
        ], self::STATE_TTL);

        return $provider->authorize_url.'?'.http_build_query([
            'client_id' => $provider->client_id,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            // Proof that whoever redeems the code is whoever asked for it. Cheap, standard, and
            // the difference between a stolen code being useful and being a string.
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Everything that happens when they come back: the code, the person, the membership.
     *
     * @return array{user: User, tenant: Tenant, membership: TenantUser}
     */
    public function claim(string $state, string $code): array
    {
        $pending = Cache::pull(self::STATE.$state);

        if (! is_array($pending)) {
            throw ApiException::unauthorized('sso_expired', 'That sign-in took too long. Please start again.');
        }

        $tenant = $this->tenants->runUnscoped(fn () => Tenant::find($pending['tenant_id']));
        $provider = $tenant ? $this->forTenant($tenant) : null;

        if (! $tenant || ! $tenant->isActive() || ! $provider || ! $provider->isUsable()) {
            throw ApiException::unauthorized('sso_unavailable', 'This account does not sign in that way.');
        }

        $person = $this->identify($provider, $code, (string) $pending['verifier']);

        if (! $person) {
            throw ApiException::unauthorized('sso_refused', 'Your provider did not confirm who you are.');
        }

        $user = $this->tenants->runUnscoped(fn () => User::where('email', $person['email'])->first());

        $membership = $user
            ? $this->tenants->runUnscoped(fn () => TenantUser::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->first())
            : null;

        if (! $user || ! $membership) {
            /*
             * Vouched for, and still not a member.
             *
             * Signing in must not create anybody: a university directory is forty thousand people,
             * and a box office is eleven of them. The refusal says what to do — be invited — rather
             * than implying the provider went wrong.
             */
            $this->tenants->runAs($tenant, fn () => $this->audit->record('sso.refused', $tenant, [
                'email' => $person['email'],
                'reason' => 'not_a_member',
            ]));

            throw ApiException::denied(
                'sso_not_a_member',
                'Your organisation confirmed who you are, but nobody here has invited you yet.'
            );
        }

        /*
         * The name from the directory, kept in step.
         *
         * Somebody who marries, or who was invited as "j.smith" before anybody knew what the J
         * stood for, should not have to correct it here as well as there: the directory is the
         * thing this account has decided to believe about its people.
         */
        if (($person['name'] ?? null) && $person['name'] !== $user->name) {
            $user->forceFill(['name' => $person['name']])->save();
        }

        $this->tenants->runAs($tenant, fn () => $this->audit->record('sso.signed_in', $user, [
            'email' => $user->email,
            'provider' => $provider->label,
        ]));

        return ['user' => $user, 'tenant' => $tenant, 'membership' => $membership];
    }

    /**
     * A handle the panel can trade for a token, once, within a minute.
     *
     * The alternative is putting a bearer token in a redirect URL, where it is written into browser
     * history, the referrer of the next request and any proxy log on the way — for a credential
     * that lasts until somebody signs out.
     */
    public function handOff(User $user, Tenant $tenant): string
    {
        $token = Str::random(64);

        Cache::put(self::HANDOFF.$token, [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ], self::HANDOFF_TTL);

        return $token;
    }

    /** @return array{user_id:string, tenant_id:string}|null */
    public function claimHandoff(string $token): ?array
    {
        $claimed = Cache::pull(self::HANDOFF.$token);

        return is_array($claimed) ? $claimed : null;
    }

    /** Where the provider sends people back to. One address, on the platform, for every account. */
    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/sso/return';
    }

    /**
     * Trade the code for the person, on the back channel.
     *
     * @return array{email:string, name:?string}|null
     */
    private function identify(IdentityProvider $provider, string $code, string $verifier): ?array
    {
        try {
            $token = Http::asForm()->timeout(12)->post($provider->token_url, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => $provider->client_id,
                'client_secret' => $provider->client_secret,
                'code_verifier' => $verifier,
            ]);

            if (! $token->successful() || ! $token->json('access_token')) {
                return null;
            }

            $person = Http::withToken((string) $token->json('access_token'))
                ->timeout(12)
                ->acceptJson()
                ->get($provider->userinfo_url);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $person->successful()) {
            return null;
        }

        $email = mb_strtolower(trim((string) $person->json('email')));
        $verified = $person->json('email_verified');

        /*
         * An address the provider has not verified is an address somebody typed.
         *
         * Providers that omit the claim entirely are taken at their word — a corporate directory
         * does not publish `email_verified` because every address in it was put there by the
         * organisation — but one that says `false` is saying something, and it is not "yes".
         */
        if ('' === $email || false === $verified || 'false' === $verified) {
            return null;
        }

        $name = trim((string) ($person->json('name') ?? ''));

        return [
            'email' => $email,
            'name' => '' === $name ? null : mb_substr($name, 0, 120),
        ];
    }
}
