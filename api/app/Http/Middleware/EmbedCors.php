<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * CORS headers for the public widget endpoints.
 *
 * These endpoints carry no secret and no buyer data, so this is a browser convenience, not an
 * access control — an attacker with curl ignores CORS entirely. Authorisation for anything that
 * matters lives in the endpoints themselves (threat T9).
 */
class EmbedCors
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->getMethod() === 'OPTIONS') {
            return response('', 204)->withHeaders($this->headers($request));
        }

        return $next($request)->withHeaders($this->headers($request));
    }

    private function headers(Request $request): array
    {
        return [
            'Access-Control-Allow-Origin' => $request->headers->get('Origin', '*'),
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Idempotency-Key, X-Request-Id',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }
}
