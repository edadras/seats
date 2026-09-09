<?php

namespace Modules\Seatmap\WhatsApp;

use App\Modules\Contracts\DeliveryResult;
use App\Modules\Contracts\MessageChannel;
use App\Modules\ModuleContext;
use App\Modules\OutboundHttp;

/**
 * WhatsApp, through the Cloud API.
 *
 * One thing an organiser has to know, and the module docs say it too: WhatsApp only allows free
 * text within 24 hours of the recipient's last message. Outside that window Meta requires an
 * approved template, and this channel does not send one — a confirmation to somebody who has never
 * written to the business will be refused by Meta, not silently dropped here, and the delivery log
 * will say so.
 *
 * That is the honest behaviour. Pretending otherwise would mean a channel that looks configured
 * and quietly reaches nobody.
 */
class WhatsAppChannel implements MessageChannel
{
    private const VERSION = 'v20.0';

    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'whatsapp';
    }

    public function labelKey(): string
    {
        return 'modules.seatmap.whatsapp.name';
    }

    public function addressKind(): string
    {
        return 'phone';
    }

    public function send(string $to, string $body, array $options = []): DeliveryResult
    {
        $token = (string) $this->context->setting('access_token');
        $number = (string) $this->context->setting('phone_number_id');

        if ('' === $token || '' === $number) {
            return DeliveryResult::refused('not_configured');
        }

        $response = (new OutboundHttp($this->context))->postJson(
            'https://graph.facebook.com/'.self::VERSION.'/'.$number.'/messages',
            [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $body],
            ],
            ['Authorization' => 'Bearer '.$token],
        );

        if ($response->successful() && $response->json('messages.0.id')) {
            return DeliveryResult::sent((string) $response->json('messages.0.id'));
        }

        if ($response->serverError()) {
            return DeliveryResult::unavailable('whatsapp_unavailable');
        }

        // Meta's own code, kept in the log: 131047 is the twenty-four-hour window, and an
        // organiser reading "whatsapp_131047" can look it up and understand what to change.
        return DeliveryResult::refused(
            'whatsapp_'.(string) $response->json('error.code', $response->status())
        );
    }
}
