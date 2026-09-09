<?php

namespace Modules\Seatmap\Twilio\Tests;

use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\Http;
use Modules\Seatmap\Twilio\Provider;
use Modules\Seatmap\Twilio\TwilioChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Twilio, against a faked Twilio. */
class TwilioChannelTest extends TestCase
{
    #[Test]
    public function it_sends_from_the_number_the_organiser_configured(): void
    {
        Http::fake(['*Messages.json' => Http::response(['sid' => 'SM123'], 201)]);

        $result = $this->channel()->send('+441234567890', 'Your tickets are booked.');

        $this->assertTrue($result->delivered());
        $this->assertSame('SM123', $result->reference);

        Http::assertSent(fn ($request) => '+441234567890' === $request['To']
            && '+441111111111' === $request['From']
            && str_contains($request->url(), '/Accounts/AC123/Messages.json'));
    }

    #[Test]
    public function a_four_hundred_is_about_this_message_and_is_never_retried(): void
    {
        Http::fake(['*' => Http::response(['code' => 21606], 400)]);

        $refused = $this->channel()->send('+441234567890', 'hello');

        $this->assertFalse($refused->retryable(), 'A landline will still be a landline tomorrow.');
        $this->assertSame('twilio_21606', $refused->reason);
    }

    #[Test]
    public function a_five_hundred_is_about_twilio_and_is_retried(): void
    {
        // Two tests rather than one, because `Http::fake()` twice in a method keeps the first
        // stub — and a test that quietly asserts the same response twice proves nothing.
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertTrue($this->channel()->send('+441234567890', 'hello')->retryable());
    }

    #[Test]
    public function the_token_travels_as_basic_auth_and_not_in_the_url(): void
    {
        Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);

        $this->channel()->send('+441234567890', 'hello');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization')
            && ! str_contains($request->url(), 'token-1'));
    }

    private function channel(): TwilioChannel
    {
        return new TwilioChannel($this->moduleContext([
            'account_sid' => 'AC123',
            'auth_token' => 'token-1',
            'from' => '+441111111111',
        ]));
    }

    private function moduleContext(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/twilio',
            'provider' => Provider::class,
            'extends' => ['messaging'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
