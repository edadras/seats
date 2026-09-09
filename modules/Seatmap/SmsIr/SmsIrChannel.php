<?php

namespace Modules\Seatmap\SmsIr;

use App\Modules\Contracts\DeliveryResult;
use App\Modules\Contracts\MessageChannel;
use App\Modules\ModuleContext;
use App\Modules\OutboundHttp;

/**
 * SMS through SMS.ir.
 *
 * The line number is required rather than optional: SMS.ir will not send without one, and finding
 * that out from a delivery log at eight in the evening is worse than being asked for it now.
 */
class SmsIrChannel implements MessageChannel
{
    private const BASE = 'https://api.sms.ir/v1/send/';

    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'sms.smsir';
    }

    public function labelKey(): string
    {
        return 'modules.seatmap.sms_ir.name';
    }

    public function addressKind(): string
    {
        return 'phone';
    }

    public function send(string $to, string $body, array $options = []): DeliveryResult
    {
        $key = (string) $this->context->setting('api_key');
        $line = (string) $this->context->setting('line_number');

        if ('' === $key || '' === $line) {
            return DeliveryResult::refused('not_configured');
        }

        $response = (new OutboundHttp($this->context))->postJson(self::BASE.'bulk', [
            'lineNumber' => $line,
            'messageText' => $body,
            'mobiles' => [$to],
        ], ['X-API-KEY' => $key]);

        $status = (int) $response->json('status', 0);

        if (1 === $status) {
            return DeliveryResult::sent((string) $response->json('data.packId', ''));
        }

        if ($response->serverError() || 0 === $status) {
            return DeliveryResult::unavailable('smsir_unavailable');
        }

        return DeliveryResult::refused('smsir_'.$status);
    }
}
