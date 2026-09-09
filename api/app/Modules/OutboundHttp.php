<?php

namespace App\Modules;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one way a module talks to the outside world.
 *
 * Modules get a context, not a container (ADR-0004 §2), so they cannot reach for an HTTP client of
 * their own with whatever timeout they felt like. This is that client: a timeout that is short
 * enough that a slow gateway cannot hold a checkout open indefinitely, one retry for the kind of
 * failure a retry fixes, and a log line on the way out that names the module rather than the URL's
 * host.
 *
 * Nothing here is provider-specific. What a request means is the module's business; that it is
 * bounded, logged and attributable is the platform's.
 */
class OutboundHttp
{
    public const TIMEOUT_SECONDS = 12;

    public function __construct(private readonly ModuleContext $context) {}

    public function postJson(string $url, array $body, array $headers = []): Response
    {
        return $this->log('POST', $url, $this->client($headers)->post($url, $body));
    }

    public function postForm(string $url, array $body, array $headers = []): Response
    {
        return $this->log('POST', $url, $this->client($headers)->asForm()->post($url, $body));
    }

    public function getJson(string $url, array $query = [], array $headers = []): Response
    {
        return $this->log('GET', $url, $this->client($headers)->get($url, $query));
    }

    private function client(array $headers): PendingRequest
    {
        return Http::withHeaders($headers + ['Accept' => 'application/json'])
            ->timeout(self::TIMEOUT_SECONDS)
            // One retry, half a second apart, and only for a connection-level failure: a gateway
            // that answered "no" answered, and asking again is how a buyer gets charged twice.
            ->retry(2, 500, throw: false)
            ->acceptJson();
    }

    private function log(string $method, string $url, Response $response): Response
    {
        if (! $response->successful()) {
            $this->context->log('warning', 'Outbound call failed', [
                'method' => $method,
                // The path only. A query string on a payment endpoint can carry a reference or a
                // token, and a log is not the place for either.
                'url' => (string) parse_url($url, PHP_URL_HOST).parse_url($url, PHP_URL_PATH),
                'status' => $response->status(),
            ]);
        }

        return $response;
    }
}
