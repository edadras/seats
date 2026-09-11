<?php

namespace App\Http\Middleware;

use App\Domain\Embed\EmbedOrigins;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Hold;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * The seat map opens where the venue said it may, and nowhere else.
 *
 * The embed carries no key — that is what makes it usable by somebody with a page and no toolchain,
 * and it is also what made it copyable: view source on a venue's booking page, paste the two tags
 * on your own site, and their hall opened there, holding seats out of their real inventory.
 *
 * **Middleware rather than a line in each action**, for the reason this codebase gives everywhere
 * else: a filter you can forget is a filter that eventually leaks. There are seven actions behind
 * this prefix today and there will be more, and the eighth is the one nobody remembers to guard.
 *
 * The tenant is resolved here from the event or the hold in the route, exactly as the controller
 * does it a moment later, because there is nothing else to resolve it from: the caller is a browser
 * on somebody else's website and has no identity to offer. That costs one indexed lookup on a
 * public read, which is the price of the answer being about the right venue.
 *
 * **What this is not.** `Origin` is a fact a browser states and will not let a page lie about; it is
 * not proof about a person. Anything that is not a browser can send whatever it likes — which is
 * also why a request stating no origin at all is let through rather than refused; see
 * {@see EmbedOrigins::allows()} for why inventing a refusal there would read as strict and buy
 * nothing. All of that is acceptable here and would not be anywhere else on this platform: what is
 * behind these endpoints is a public programme and a chart the venue already shows the world, so
 * what is being defended is the venue's brand on somebody else's page and their inventory's rate
 * limits — not a secret. Every endpoint that guards something secret authenticates properly and
 * does not rely on this.
 */
class AllowEmbedOrigin
{
    public function __construct(
        private readonly EmbedOrigins $origins,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $tenantId = $this->tenantOf($request);

        // No event and no hold in the route means the controller is about to answer 404 anyway.
        // Refusing here instead would tell a stranger which ids exist, one guess at a time.
        if (null === $tenantId) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');

        if (! $this->origins->allows($tenantId, $origin)) {
            /*
             * Said plainly, and about the website rather than about the event.
             *
             * Whoever is looking at this is one of two people: an organiser who has just pasted the
             * snippet onto a page they forgot to add to the list, or somebody who took it. The
             * first needs to know exactly what to do, and telling the second costs nothing — they
             * already know which site they are on.
             */
            throw ApiException::denied(
                'embed_origin_not_allowed',
                __('errors.embed_origin_not_allowed'),
            );
        }

        $this->origins->seen($tenantId, $origin);

        return $next($request);
    }

    /**
     * Whose hall this request is about.
     *
     * Unscoped on purpose and reading one column: the tenant is not known yet, that is the whole
     * problem, and this must not pull an event's relations only to throw them away when the
     * controller loads them properly.
     */
    private function tenantOf(Request $request): ?string
    {
        return $this->tenants->runUnscoped(function () use ($request) {
            if ($publicId = $request->route('public_id')) {
                return Event::where('public_id', $publicId)->value('tenant_id');
            }

            if ($token = $request->route('token')) {
                return Hold::where('token', $token)->value('tenant_id');
            }

            return null;
        });
    }
}
