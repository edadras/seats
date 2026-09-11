<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Auth\SingleSignOn;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\IdentityProvider;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * An account's own identity provider, from the inside.
 *
 * Behind `account.manage`: deciding who may sign in at all is the same kind of decision as deciding
 * who is on the team, and a larger one than either.
 *
 * Saving always rediscovers. An issuer's well-known document is read at save time so that a typo is
 * found while somebody is looking at the screen rather than at half past seven on a Friday by a
 * member of staff who cannot fix it — and rediscovering on every save means an issuer that has
 * moved an endpoint is picked up by pressing Save rather than by support.
 */
class SsoController extends Controller
{
    public function __construct(
        private readonly SingleSignOn $sso,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $tenant = $this->tenantOf($request);

        return response()->json($this->present($tenant, $this->sso->forTenant($tenant)));
    }

    public function save(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $tenant = $this->tenantOf($request);
        $existing = $this->sso->forTenant($tenant);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            // https only, and said as a rule rather than checked later: an issuer reached over
            // plain http is an issuer anybody on the path can be.
            'issuer' => ['required', 'url:https', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            // Optional on an update: a secret that has to be retyped to change a label is a secret
            // that ends up in somebody's notes.
            'client_secret' => [$existing ? 'sometimes' : 'required', 'string', 'max:500'],
            'enabled' => ['sometimes', 'boolean'],
            'required' => ['sometimes', 'boolean'],
        ]);

        $found = $this->sso->discover($data['issuer']);

        $provider = $existing ?? new IdentityProvider(['tenant_id' => $tenant->id]);

        $provider->forceFill([
            'tenant_id' => $tenant->id,
            'label' => $data['label'],
            'issuer' => rtrim($data['issuer'], '/'),
            'client_id' => $data['client_id'],
            'enabled' => (bool) ($data['enabled'] ?? $provider->enabled ?? true),
            'required' => (bool) ($data['required'] ?? $provider->required ?? false),
        ] + $found + ['discovered_at' => now()]);

        if (! empty($data['client_secret'])) {
            $provider->client_secret = $data['client_secret'];
        }

        $provider->save();

        $this->audit->record('sso.saved', $provider, [
            'issuer' => $provider->issuer,
            'enabled' => $provider->enabled,
            'required' => $provider->required,
        ]);

        return response()->json($this->present($tenant, $provider->fresh()));
    }

    /**
     * Stop signing in that way.
     *
     * Deleting the provider rather than switching it off, because "off" and "gone" are different
     * answers to "may we stop paying for this directory" and only one of them is what somebody
     * pressing this meant. Passwords work again the moment it goes.
     */
    public function destroy(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $tenant = $this->tenantOf($request);
        $provider = $this->sso->forTenant($tenant);

        if ($provider) {
            $this->audit->record('sso.removed', $tenant, ['issuer' => $provider->issuer]);
            $provider->delete();
        }

        return response()->json($this->present($tenant, null));
    }

    /* --------------------------------------------------------------------------- helpers */

    private function tenantOf(Request $request): Tenant
    {
        return $request->attributes->get('tenant')
            ?? Tenant::findOrFail(app(\App\Support\Tenancy\TenantContext::class)->idOrFail());
    }

    private function present(Tenant $tenant, ?IdentityProvider $provider): array
    {
        return [
            'configured' => null !== $provider,
            'label' => $provider?->label,
            'issuer' => $provider?->issuer,
            'client_id' => $provider?->client_id,
            // Never the secret itself, in any shape. There is no reading one back, only replacing it.
            'has_secret' => (bool) $provider?->client_secret,
            'enabled' => (bool) $provider?->enabled,
            'required' => (bool) $provider?->required,
            'discovered_at' => $provider?->discovered_at?->toIso8601String(),
            'endpoints' => $provider ? [
                'authorize' => $provider->authorize_url,
                'token' => $provider->token_url,
                'userinfo' => $provider->userinfo_url,
            ] : null,
            /*
             * The two addresses this account has to hand somebody else.
             *
             * The first goes into the provider's own configuration; the second goes to the staff,
             * or onto a tile in the provider's dashboard. Both are composed here rather than
             * described in a help page, because an organiser copying a URL out of prose gets it
             * wrong once in five and the failure is a screen that says nothing useful.
             */
            'redirect_uri' => $this->sso->redirectUri(),
            'sign_in_url' => rtrim((string) config('app.url'), '/').'/sso/'.$tenant->slug,
        ];
    }
}
