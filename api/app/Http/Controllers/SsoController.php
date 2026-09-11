<?php

namespace App\Http\Controllers;

use App\Domain\Auth\SingleSignOn;
use App\Exceptions\ApiException;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * The two ends of a single sign-on: away to the provider, and back again.
 *
 * Plain browser routes rather than API ones, because a redirect to somebody else's login page and
 * back is a thing only a browser can do. Neither of them is authenticated — being unauthenticated
 * is the entire point — so both are throttled and neither says anything a stranger could learn from.
 *
 * The start route is addressed by the account's own slug. That is deliberate: an organisation hands
 * its staff one address, or pins it as a tile in their provider's dashboard, and nobody has to type
 * an email into a form that would then have to answer whether that email exists.
 */
class SsoController extends Controller
{
    public function __construct(
        private readonly SingleSignOn $sso,
        private readonly TenantContext $tenants,
    ) {}

    /** Away to the provider. */
    public function start(Request $request, string $slug)
    {
        $tenant = $this->tenants->runUnscoped(
            fn () => Tenant::where('slug', mb_strtolower(trim($slug)))->first()
        );

        $provider = $tenant?->isActive() ? $this->sso->forTenant($tenant) : null;

        if (! $tenant || ! $provider || ! $provider->isUsable()) {
            /*
             * One answer for "no such account" and "that account does not sign in this way".
             *
             * Sent back to the ordinary sign-in page rather than refused, because the person at the
             * keyboard is a member of staff who was given a link, and the useful thing to do with
             * them is show them the screen they can actually use.
             */
            return redirect('/?sso=unavailable');
        }

        return redirect()->away($this->sso->beginUrl($tenant, $provider));
    }

    /**
     * And back again.
     *
     * Everything in this request came from somewhere else, so nothing in it is believed: the state
     * is looked up rather than read, the code is spent on the back channel, and who the person is
     * comes from the provider rather than from the query string.
     */
    public function return(Request $request)
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ('' === $state || '' === $code) {
            // The provider refused, or the person changed their mind at its screen. Either way
            // there is nothing to report to them that they do not already know.
            return redirect('/?sso=refused');
        }

        try {
            $signedIn = $this->sso->claim($state, $code);
        } catch (ApiException $e) {
            return redirect('/?sso='.($e->errorCode() === 'sso_not_a_member' ? 'stranger' : 'refused'));
        }

        // A handle rather than a token: a bearer credential in a redirect URL is written into
        // browser history, the next request's referrer and any proxy log between here and there.
        return redirect('/?sso=ok&handoff='.$this->sso->handOff($signedIn['user'], $signedIn['tenant']));
    }
}
