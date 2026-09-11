<?php

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // No '/api' prefix: the published contract is /v1/..., and the plugin signs the path,
        // so the URL shape is part of the interface rather than a deployment detail.
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * What a proxy is believed about, which is less than the framework's default.
         *
         * Who the proxies are is in `config/trustedproxy.php`; this is the other half — which
         * forwarded headers are honoured once one is trusted. Three of them, and the omission is
         * the point: `X-Forwarded-Host` is left out because this application *routes by Host*.
         * Every request's hostname is looked up as a tenant's site, so a Host header a client
         * could set would be one tenant serving their page on another's domain — and on a
         * deployment that has to trust a shared load balancer, the header is exactly that.
         * `X-Forwarded-Prefix` is out for the same kind of reason: nothing here is served under a
         * path prefix, so believing one only moves URLs somewhere nobody asked for.
         *
         * What is honoured is what the platform genuinely cannot work without: the client's
         * address, because every per-IP limit depends on it, and the scheme and port, so a request
         * that arrived over TLS is not treated as plain HTTP by the session cookie.
         */
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->api(prepend: [
            \App\Http\Middleware\AssignRequestId::class,
        ]);

        // Tenant resolution must precede route model binding. SubstituteBindings sits in the
        // framework's priority list and would otherwise run first, resolving {event}/{venue} with
        // no tenant bound — where the fail-closed scope matches nothing and every panel route
        // with a bound model 404s.
        foreach ([
            \App\Http\Middleware\ResolveTenantFromUser::class,
            \App\Http\Middleware\AuthenticateApiClient::class,
            \App\Http\Middleware\ResolveCheckinDevice::class,
            \App\Http\Middleware\ResolveSiteFromHost::class,
        ] as $tenantResolver) {
            $middleware->prependToPriorityList(
                before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
                prepend: $tenantResolver,
            );
        }

        // Runs after the tenant and site resolvers above (they are prepended, so they end up
        // ahead of it) and before anything that renders a message to a person.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\ResolveLocale::class,
        );

        $middleware->web(append: [\App\Http\Middleware\ResolveLocale::class]);
        $middleware->api(append: [\App\Http\Middleware\ResolveLocale::class]);

        $middleware->alias([
            'api.client' => \App\Http\Middleware\AuthenticateApiClient::class,
            // What a key is for, checked after who it belongs to.
            'scope' => \App\Http\Middleware\RequireApiScope::class,
            'locale' => \App\Http\Middleware\ResolveLocale::class,
            'tenant' => \App\Http\Middleware\ResolveTenantFromUser::class,
            'idempotency' => \App\Http\Middleware\EnforceIdempotency::class,
            'device' => \App\Http\Middleware\ResolveCheckinDevice::class,
            'site' => \App\Http\Middleware\ResolveSiteFromHost::class,
            'platform' => \App\Http\Middleware\RequirePlatformAdmin::class,
            // After model binding, which is where the event it scopes comes from.
            'managed' => \App\Http\Middleware\ScopeToManagedEvents::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every API failure leaves through one envelope. Without this, validation and 404s would
        // come back shaped differently from deliberate domain errors and clients would need two
        // parsers.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('v1/*') && ! $request->expectsJson()) {
                return null;
            }

            $requestId = $request->attributes->get('request_id');

            if ($e instanceof ApiException) {
                return $e->render($request);
            }

            // Framework failures leave through the same envelope *and* the same catalogue as the
            // deliberate ones: a 404 from route model binding should read like a 404 we threw.
            [$code, $message, $status, $details] = match (true) {
                $e instanceof ValidationException => [
                    'validation_failed', __('errors.validation_failed'), 422, $e->errors(),
                ],
                $e instanceof AuthenticationException => [
                    'unauthenticated', __('errors.unauthenticated'), 401, [],
                ],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [
                    'not_found', __('errors.not_found'), 404, [],
                ],
                $e instanceof HttpExceptionInterface => [
                    'http_error', $e->getMessage() ?: __('errors.http_error'), $e->getStatusCode(), [],
                ],
                default => [
                    'server_error',
                    // Debug mode shows the real exception, untranslated on purpose: it is for
                    // whoever is reading a stack trace, not for a buyer.
                    app()->hasDebugModeEnabled() ? $e->getMessage() : __('errors.server_error'),
                    500,
                    [],
                ],
            };

            return response()->json([
                'error' => array_filter([
                    'code' => $code,
                    'message' => $message,
                    'details' => $details ?: null,
                    'request_id' => $requestId,
                ], fn ($v) => $v !== null),
            ], $status);
        });
    })->create();
