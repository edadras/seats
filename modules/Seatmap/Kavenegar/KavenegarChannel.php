<?php

namespace Modules\Seatmap\Kavenegar;

use App\Modules\OutboundHttp;
use App\Modules\Contracts\DeliveryResult;
use App\Modules\Contracts\MessageChannel;
use App\Modules\ModuleContext;

/**
 * SMS through Kavenegar (کاوه‌نگار).
 *
 * The API key is in the *path*, which is unusual and worth knowing: it means the key must never be
 * logged with the URL, and `OutboundHttp` logs a host and path only on failure — so this channel
 * builds its URL late and keeps the key out of anything it writes itself.
 *
 * Kavenegar answers 200 for accepted; anything else has a `return.status` that says why. A bad
 * number is a refusal and must never be retried; a 5xx is the provider being down and always
 * should be.
 */
class KavenegarChannel implements MessageChannel
{
    private const BASE = 'https://api.kavenegar.com/v1/';

    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'sms.kavenegar';
    }

    public function labelKey(): string
    {
        return 'modules.seatmap.kavenegar.name';
    }

    public function addressKind(): string
    {
        return 'phone';
    }

    public function send(string $to, string $body, array $options = []): DeliveryResult
    {
        $key = (string) $this->context->setting('api_key');

        if ('' === $key) {
            return DeliveryResult::refused('not_configured');
        }

        $response = (new OutboundHttp($this->context))->postForm(
            self::BASE.$key.'/sms/send.json',
            array_filter([
                'receptor' => $to,
                'message' => $body,
                'sender' => $this->context->setting('sender') ?: null,
            ]),
        );

        $status = (int) $response->json('return.status', 0);

        if (200 === $status) {
            return DeliveryResult::sent((string) $response->json('entries.0.messageid', ''));
        }

        // 5xx, or no answer at all: the provider is having a bad day, and this message should be
        // tried again. Everything else is Kavenegar telling us something about this message.
        if ($response->serverError() || 0 === $status) {
            return DeliveryResult::unavailable('kavenegar_unavailable');
        }

        return DeliveryResult::refused('kavenegar_'.$status);
    }
}
