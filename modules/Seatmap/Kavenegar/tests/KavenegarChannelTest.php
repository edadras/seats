<?php

namespace Modules\Seatmap\Kavenegar\Tests;

use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\Http;
use Modules\Seatmap\Kavenegar\KavenegarChannel;
use Modules\Seatmap\Kavenegar\Provider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kavenegar, against a faked Kavenegar.
 *
 * The distinction under test is the one the whole delivery model rests on: a provider that said no
 * must never be retried, and a provider that was unreachable always should be.
 */
class KavenegarChannelTest extends TestCase
{
    #[Test]
    public function a_sent_message_carries_the_providers_own_id(): void
    {
        Http::fake(['*sms/send.json' => Http::response([
            'return' => ['status' => 200],
            'entries' => [['messageid' => 8827991]],
        ])]);

        $result = $this->channel()->send('09121234567', 'سلام');

        $this->assertTrue($result->delivered());
        $this->assertSame('8827991', $result->reference);

        Http::assertSent(fn ($request) => '09121234567' === $request['receptor']
            && 'سلام' === $request['message']
            && str_contains($request->url(), '/v1/key-1/sms/send.json'));
    }

    #[Test]
    public function a_bad_number_is_refused_and_not_retried(): void
    {
        Http::fake(['*' => Http::response(['return' => ['status' => 411, 'message' => 'invalid receptor']], 400)]);

        $result = $this->channel()->send('nonsense', 'hello');

        $this->assertFalse($result->delivered());
        $this->assertFalse($result->retryable(), 'Kavenegar will say the same thing next time.');
    }

    #[Test]
    public function a_provider_that_is_down_is_retryable(): void
    {
        Http::fake(['*' => Http::response('gateway down', 503)]);

        $this->assertTrue($this->channel()->send('09121234567', 'hello')->retryable());
    }

    #[Test]
    public function a_channel_with_no_key_refuses_rather_than_calling_anyone(): void
    {
        Http::fake();

        $result = (new KavenegarChannel($this->moduleContext()))->send('09121234567', 'hello');

        $this->assertSame('not_configured', $result->reason);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_is_an_sms_channel_and_says_so(): void
    {
        $this->assertSame('phone', $this->channel()->addressKind());
        $this->assertSame('sms.kavenegar', $this->channel()->key());
        $this->assertCount(1, (new Provider($this->moduleContext()))->messaging());
    }

    private function channel(): KavenegarChannel
    {
        return new KavenegarChannel($this->moduleContext(['api_key' => 'key-1', 'sender' => '10008888']));
    }

    private function moduleContext(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/kavenegar',
            'provider' => Provider::class,
            'extends' => ['messaging'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
