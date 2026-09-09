<?php

namespace Modules\Seatmap\SmsIr\Tests;

use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\Http;
use Modules\Seatmap\SmsIr\Provider;
use Modules\Seatmap\SmsIr\SmsIrChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SMS.ir, against a faked SMS.ir. */
class SmsIrChannelTest extends TestCase
{
    #[Test]
    public function it_sends_from_the_configured_line(): void
    {
        Http::fake(['*send/bulk' => Http::response(['status' => 1, 'data' => ['packId' => 'p-9']])]);

        $result = $this->channel()->send('09121234567', 'سلام');

        $this->assertTrue($result->delivered());
        $this->assertSame('p-9', $result->reference);

        Http::assertSent(fn ($request) => '30007' === $request['lineNumber']
            && ['09121234567'] === $request['mobiles']
            && 'key-1' === $request->header('X-API-KEY')[0]);
    }

    #[Test]
    public function a_missing_line_number_refuses_before_calling(): void
    {
        Http::fake();

        $result = (new SmsIrChannel($this->moduleContext(['api_key' => 'key-1'])))
            ->send('09121234567', 'hello');

        $this->assertSame('not_configured', $result->reason);
        Http::assertNothingSent();
    }

    #[Test]
    public function an_outage_is_retryable(): void
    {
        Http::fake(['*' => Http::response('', 502)]);

        $this->assertTrue($this->channel()->send('09121234567', 'hello')->retryable());
    }

    #[Test]
    public function a_rejection_is_not_retryable(): void
    {
        Http::fake(['*' => Http::response(['status' => 20], 400)]);

        $this->assertFalse($this->channel()->send('09121234567', 'hello')->retryable());
    }

    private function channel(): SmsIrChannel
    {
        return new SmsIrChannel($this->moduleContext([
            'api_key' => 'key-1', 'line_number' => '30007',
        ]));
    }

    private function moduleContext(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/sms-ir',
            'provider' => Provider::class,
            'extends' => ['messaging'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
