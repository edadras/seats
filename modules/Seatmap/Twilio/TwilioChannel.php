<?php

namespace Modules\Seatmap\Twilio;

use App\Modules\Contracts\DeliveryResult;
use App\Modules\Contracts\MessageChannel;
use App\Modules\ModuleContext;
use App\Modules\OutboundHttp;

/**
 * SMS through Twilio.
 *
 * Twilio is precise about the difference this platform cares about: a 4xx is Twilio saying no to
 * *this message* — an unreachable region, a number that is a landline — and a 5xx is Twilio being
 * unavailable. The first must never be retried and the second always should be, so the status code
 * is read rather than the body.
 */
class TwilioChannel implements MessageChannel
{
    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'sms.twilio';
    }

    public function labelKey(): string
    {
        return 'modules.seatmap.twilio.name';
    }

    public function addressKind(): string
    {
        return 'phone';
    }

    public function send(string $to, string $body, array $options = []): DeliveryResult
    {
        $sid = (string) $this->context->setting('account_sid');
        $token = (string) $this->context->setting('auth_token');
        $from = (string) $this->context->setting('from');

        if ('' === $sid || '' === $token || '' === $from) {
            return DeliveryResult::refused('not_configured');
        }

        $response = (new OutboundHttp($this->context))->postForm(
            'https://api.twilio.com/2010-04-01/Accounts/'.$sid.'/Messages.json',
            ['To' => $to, 'From' => $from, 'Body' => $body],
            ['Authorization' => 'Basic '.base64_encode($sid.':'.$token)],
        );

        if ($response->successful()) {
            return DeliveryResult::sent((string) $response->json('sid', ''));
        }

        if ($response->serverError()) {
            return DeliveryResult::unavailable('twilio_unavailable');
        }

        return DeliveryResult::refused('twilio_'.(string) $response->json('code', $response->status()));
    }
}
