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
        ] as $tenantResolver) {
            $middleware->prependToPriorityList(
                before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
                prepend: $tenantResolver,
            );
        }

        $middleware->alias([
            'api.client' => \App\Http\Middleware\AuthenticateApiClient::class,
            'tenant' => \App\Http\Middleware\ResolveTenantFromUser::class,
            'idempotency' => \App\Http\Middleware\EnforceIdempotency::class,
            'device' => \App\Http\Middleware\ResolveCheckinDevice::class,
            'embed.cors' => \App\Http\Middleware\EmbedCors::class,
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

            [$code, $message, $status, $details] = match (true) {
                $e instanceof ValidationException => [
                    'validation_failed', 'The request payload is invalid.', 422, $e->errors(),
                ],
                $e instanceof AuthenticationException => [
                    'unauthenticated', 'Authentication required.', 401, [],
                ],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [
                    'not_found', 'Resource not found.', 404, [],
                ],
                $e instanceof HttpExceptionInterface => [
                    'http_error', $e->getMessage() ?: 'Request failed.', $e->getStatusCode(), [],
                ],
                default => [
                    'server_error',
                    app()->hasDebugModeEnabled() ? $e->getMessage() : 'An unexpected error occurred.',
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
