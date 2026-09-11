<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who the client is, when something else is speaking for it.
 *
 * Almost everything this platform limits, it limits per IP address: signing in, signing up,
 * claiming an SSO account, how many seats one person may hold, the bot defence on the picker. Every
 * audit row records one too. So `$request->ip()` is not a diagnostic detail here — it is the key
 * those limits are counted against, and getting it wrong has two failure modes that are opposite
 * and equally bad:
 *
 *   - **Trusting nothing behind a proxy.** Every buyer at an on-sale arrives as the load balancer,
 *     so they share one throttle bucket and the tenth of them is refused on everyone else's behalf.
 *   - **Trusting everything when directly exposed.** Any client sets `X-Forwarded-For` to a
 *     different address on each attempt and walks past the same limits from one machine.
 *
 * Neither shows up on a smoke test of a working page, which is why they are pinned here.
 *
 * The third test is the one that is about this application rather than about proxies in general:
 * `X-Forwarded-Host` is *not* honoured even from a trusted proxy, because hostnames are how this
 * platform decides whose site a request belongs to.
 */
class BehindAProxyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_untrusted_client_cannot_claim_to_be_somebody_else(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->assertSame('127.0.0.1', $this->ipSeenBy(['X-Forwarded-For' => '203.0.113.9']));
    }

    #[Test]
    public function a_trusted_proxy_is_believed_about_the_client(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1,::1']);

        $this->assertSame('203.0.113.9', $this->ipSeenBy(['X-Forwarded-For' => '203.0.113.9']));
    }

    #[Test]
    public function the_scheme_survives_a_proxy_that_terminated_the_tls(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1,::1']);

        $request = $this->requestThrough(['X-Forwarded-Proto' => 'https']);

        $this->assertTrue($request->isSecure());
    }

    /**
     * The one header deliberately left out of the trusted set.
     *
     * This application routes by Host: the hostname is looked up as a tenant's site. A forwarded
     * Host that a client could set would therefore be one organiser serving their pages on
     * another's domain — and on any deployment sharing a load balancer, the header is exactly that.
     */
    #[Test]
    public function a_forwarded_host_is_not_believed_even_from_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1,::1']);

        $request = $this->requestThrough([
            'X-Forwarded-Host' => 'somebody-elses-venue.test',
        ], 'http://northgate.test/up');

        $this->assertSame('northgate.test', $request->getHost());
    }

    /* --------------------------------------------------------------------------- helpers */

    private function ipSeenBy(array $headers): string
    {
        return (string) $this->requestThrough($headers)->ip();
    }

    /**
     * One real trip through the middleware stack, because that is where the trust is applied.
     *
     * The probe is registered under `/up…` deliberately. Everything else is swallowed by the front
     * door's catch-all, which was registered when the application booted and therefore matches
     * first; the health route's prefix is one of the handful the catch-all excludes.
     */
    private function requestThrough(array $headers, string $url = 'http://localhost/up'): \Illuminate\Http\Request
    {
        $seen = null;

        $this->app['router']->get('/up-proxy-probe', function (\Illuminate\Http\Request $request) use (&$seen) {
            $seen = $request;

            return response('');
        });

        $this->call('GET', str_replace('/up', '/up-proxy-probe', $url), [], [], [], $this->server($headers));

        return $seen;
    }

    /** @return array<string, string> */
    private function server(array $headers): array
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $server;
    }
}
