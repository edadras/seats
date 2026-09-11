<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The command that says whether this installation is fit to take money.
 *
 * Its value is entirely in the failures, so those are what is pinned here: a check that quietly
 * stopped noticing is worse than no check, because somebody read a green report and opened the
 * doors on it.
 *
 * The exit status is part of the interface and is tested as such — a deploy script is expected to
 * stop on it, and a command that prints "FAIL" in red and returns success is a command that gets
 * deployed past.
 */
class PreflightTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_working_installation_passes(): void
    {
        $this->healthy();

        $this->artisan('seatmap:preflight')
            ->assertExitCode(0)
            ->expectsOutputToContain('Fit to take money.');
    }

    /**
     * The quietest way this platform can be broken: mail configured to write to a log file. Nobody
     * receives a ticket and nothing anywhere reports an error, so it has to be a failure rather
     * than a warning.
     */
    #[Test]
    public function mail_that_goes_nowhere_is_a_failure(): void
    {
        $this->healthy();
        config(['mail.default' => 'log']);

        $this->artisan('seatmap:preflight')
            ->assertExitCode(1)
            ->expectsOutputToContain('Not fit to take money yet.');
    }

    #[Test]
    public function a_missing_signing_key_is_a_failure(): void
    {
        $this->healthy();
        config(['seatmap.signing_key' => '']);

        $this->artisan('seatmap:preflight')->assertExitCode(1);
    }

    #[Test]
    public function running_jobs_inside_the_request_is_a_failure(): void
    {
        $this->healthy();
        config(['queue.default' => 'sync']);

        $this->artisan('seatmap:preflight')->assertExitCode(1);
    }

    #[Test]
    public function an_unverified_webhook_destination_is_a_failure_in_production(): void
    {
        $this->healthy();
        config(['seatmap.webhooks.verify_destination' => false]);

        // Only in production: a development receiver is http://localhost, and a guard that refuses
        // every fixture is a guard somebody disables for good.
        $this->artisan('seatmap:preflight')->assertExitCode(0);

        app()['env'] = 'production';
        config(['app.env' => 'production', 'app.debug' => false]);

        $this->artisan('seatmap:preflight')->assertExitCode(1);
    }

    /**
     * A decision nobody made is not the same as something broken — but `--strict` is there so that
     * a deployment can insist every one of them has been made.
     */
    #[Test]
    public function an_untrusted_proxy_is_a_decision_rather_than_a_fault(): void
    {
        $this->healthy();
        config(['trustedproxy.proxies' => null]);

        $this->artisan('seatmap:preflight')->assertExitCode(0);
        $this->artisan('seatmap:preflight', ['--strict' => true])->assertExitCode(1);
    }

    #[Test]
    public function trusting_every_proxy_is_called_out(): void
    {
        $this->healthy();
        config(['trustedproxy.proxies' => '*']);

        $this->artisan('seatmap:preflight')
            ->assertExitCode(0)
            ->expectsOutputToContain('walk past');
    }

    /**
     * Everything the command needs to be happy about, so each test above changes exactly one thing
     * and the exit status can only be about that one thing.
     */
    private function healthy(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.debug' => false,
            'app.url' => 'https://panel.example',
            'seatmap.signing_key' => str_repeat('k', 32),
            'seatmap.sites.panel_hosts' => ['panel.example'],
            'seatmap.sites.scheme' => 'https',
            'seatmap.sites.default_domain' => 'venues.example',
            'seatmap.webhooks.verify_destination' => true,
            'trustedproxy.proxies' => '127.0.0.1,::1',
            'mail.default' => 'smtp',
            'mail.from.address' => 'no-reply@panel.example',
            'queue.default' => 'redis',
            'cache.default' => 'redis',
        ]);
    }
}
