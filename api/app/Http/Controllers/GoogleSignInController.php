<?php

namespace App\Http\Controllers;

use App\Domain\Sites\Auth\GoogleIdentity;
use App\Models\Site;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where Google sends a buyer back to — on the platform's own host, for every site on it.
 *
 * This route exists on this host and nowhere else because a redirect URI has to be registered with
 * Google in advance, and organisers' sites live on domains this platform learns about after the
 * fact. One URI is registered; the buyer is handed on to their own site with a one-time token.
 *
 * Nothing here is inside the site middleware, so there is no tenant bound: the site is found from
 * the state nonce this application issued, unscoped, and then everything else follows from that.
 */
class GoogleSignInController extends Controller
{
    public function __construct(
        private readonly GoogleIdentity $google,
        private readonly TenantContext $tenants,
    ) {}

    public function __invoke(Request $request)
    {
        if (! $this->google->configured()) {
            throw new NotFoundHttpException('Signing in with Google is not configured.');
        }

        $state = $this->google->claimState((string) $request->query('state'));

        if (! $state) {
            // Expired, already used, or never issued. There is nowhere safe to send somebody whose
            // state we cannot read — the site to return to is what the state was carrying.
            throw new NotFoundHttpException('That sign-in has expired. Please start again.');
        }

        $site = $this->tenants->runUnscoped(fn () => Site::find($state['site_id']));

        if (! $site || ! $site->google_signin) {
            throw new NotFoundHttpException('That site does not offer signing in.');
        }

        // The buyer pressed cancel, or Google refused. Back to their page with a word about it,
        // rather than an error page on a domain they have never heard of.
        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->away($site->url('/account?signin=cancelled'));
        }

        $person = $this->google->identify((string) $request->query('code'));

        if (! $person) {
            return redirect()->away($site->url('/account?signin=failed'));
        }

        return redirect()->away($site->url('/account/google/finish?'.http_build_query([
            'token' => $this->google->handOff($site, $person),
            'return' => $state['return'],
        ])));
    }
}
