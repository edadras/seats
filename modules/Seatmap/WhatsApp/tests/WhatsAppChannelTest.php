<?php

namespace Modules\Seatmap\WhatsApp\Tests;

use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\Http;
use Modules\Seatmap\WhatsApp\Provider;
use Modules\Seatmap\WhatsApp\WhatsAppChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** WhatsApp Cloud API, against a faked Meta. */
class WhatsAppChannelTest extends TestCase
{
    #[Test]
    public function a_message_inside_the_window_goes(): void
    {
        Http::fake(['*messages' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $result = $this->channel()->send('+441234567890', 'Your tickets are booked.');

        $this->assertTrue($result->delivered());
        $this->assertSame('wamid.1', $result->reference);

        Http::assertSent(fn ($request) => 'whatsapp' === $request['messaging_product']
            && 'Your tickets are booked.' === $request['text']['body']);
    }

    #[Test]
    public function outside_the_window_metas_own_code_is_kept_so_it_can_be_looked_up(): void
    {
        // 131047 is "re-engagement message": free text outside twenty-four hours. This module
        // sends plain text and says so; the log has to make that legible rather than "failed".
        Http::fake(['*' => Http::response(['error' => ['code' => 131047]], 400)]);

        $result = $this->channel()->send('+441234567890', 'hello');

        $this->assertFalse($result->retryable());
        $this->assertSame('whatsapp_131047', $result->reason);
    }

    #[Test]
    public function meta_being_down_is_retryable(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertTrue($this->channel()->send('+441234567890', 'hello')->retryable());
    }

    private function channel(): WhatsAppChannel
    {
        return new WhatsAppChannel($this->moduleContext([
            'access_token' => 'token', 'phone_number_id' => '10987',
        ]));
    }

    private function moduleContext(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/whatsapp',
            'provider' => Provider::class,
            'extends' => ['messaging'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
