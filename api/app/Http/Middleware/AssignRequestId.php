<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gives every request a correlation id, echoes it back, and puts it into the log context so a
 * support ticket quoting one id can be traced through application logs, audit rows and webhook
 * deliveries.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = $request->header('X-Request-Id');

        // Never trust a client-supplied value verbatim in logs: bound it and strip anything odd.
        if (! is_string($requestId) || ! preg_match('/^[A-Za-z0-9_.\-]{8,64}$/', $requestId)) {
            $requestId = (string) Str::uuid();
        }

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
