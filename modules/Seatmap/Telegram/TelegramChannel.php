<?php

namespace Modules\Seatmap\Telegram;

use App\Modules\Contracts\DeliveryResult;
use App\Modules\Contracts\MessageChannel;
use App\Modules\ModuleContext;
use App\Modules\OutboundHttp;

/**
 * Telegram, through a bot.
 *
 * The address is a chat id — a number Telegram gives when somebody has *started a conversation
 * with the bot*. A bot cannot message a stranger, which is Telegram's design and a good one: this
 * channel therefore only reaches buyers who chose to be reachable, and a buyer with no chat id is
 * simply not sent this one.
 *
 * 403 means the buyer blocked the bot. That is a refusal, forever, and retrying it is how an
 * account ends up rate-limited for messaging people who said no.
 */
class TelegramChannel implements MessageChannel
{
    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'telegram';
    }

    public function labelKey(): string
    {
        return 'modules.seatmap.telegram.name';
    }

    public function addressKind(): string
    {
        return 'handle';
    }

    public function send(string $to, string $body, array $options = []): DeliveryResult
    {
        $token = (string) $this->context->setting('bot_token');

        if ('' === $token) {
            return DeliveryResult::refused('not_configured');
        }

        $response = (new OutboundHttp($this->context))->postJson(
            'https://api.telegram.org/bot'.$token.'/sendMessage',
            ['chat_id' => $to, 'text' => $body, 'disable_web_page_preview' => true],
        );

        if (true === $response->json('ok')) {
            return DeliveryResult::sent((string) $response->json('result.message_id', ''));
        }

        if ($response->serverError()) {
            return DeliveryResult::unavailable('telegram_unavailable');
        }

        return DeliveryResult::refused('telegram_'.(string) $response->json('error_code', $response->status()));
    }
}
