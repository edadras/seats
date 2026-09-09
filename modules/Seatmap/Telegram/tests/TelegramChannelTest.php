<?php

namespace Modules\Seatmap\Telegram\Tests;

use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\Http;
use Modules\Seatmap\Telegram\Provider;
use Modules\Seatmap\Telegram\TelegramChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Telegram, against a faked Telegram. */
class TelegramChannelTest extends TestCase
{
    #[Test]
    public function it_sends_to_a_chat_id(): void
    {
        Http::fake(['*sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 42]])]);

        $result = $this->channel()->send('123456789', 'Your tickets are booked.');

        $this->assertTrue($result->delivered());
        $this->assertSame('42', $result->reference);
    }

    #[Test]
    public function somebody_who_blocked_the_bot_is_a_refusal_forever(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 403], 403)]);

        $result = $this->channel()->send('123456789', 'hello');

        $this->assertFalse($result->retryable(), 'Asking again is messaging somebody who said no.');
        $this->assertSame('telegram_403', $result->reason);
    }

    #[Test]
    public function the_address_is_a_handle_because_a_bot_cannot_write_to_a_stranger(): void
    {
        $this->assertSame('handle', $this->channel()->addressKind());
    }

    private function channel(): TelegramChannel
    {
        return new TelegramChannel($this->moduleContext(['bot_token' => '123:ABC']));
    }

    private function moduleContext(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/telegram',
            'provider' => Provider::class,
            'extends' => ['messaging'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
