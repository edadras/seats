<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;

/**
 * What this key is for, checked against what this request is.
 *
 * Sits after {@see AuthenticateApiClient}, which has already proved the key is real and put it on
 * the request. This only asks the narrower question — and it is deliberately a *refusal* rather
 * than a 404: the caller holds a valid credential and is being told that this particular key may
 * not do this particular thing, which is something they can act on by issuing themselves another.
 *
 * A key with no scopes at all may do everything, which is what every key issued before scopes
 * existed could already do. Narrowing those silently would take a working shop off sale.
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        $key = $request->attributes->get('api_key');

        if (! $key instanceof ApiKey) {
            // Nothing authenticated this request. The only way here is a misordered middleware
            // stack, and answering "allowed" would turn that mistake into an open door.
            throw ApiException::unauthorized('invalid_key', 'API key is unknown, expired or revoked.');
        }

        if (! $key->allows($scope)) {
            throw ApiException::denied(
                'key_scope',
                'This key is not allowed to do that.',
            );
        }

        return $next($request);
    }
}
