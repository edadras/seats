<?php

namespace App\Domain\Messaging;

use App\Models\MessageChannelSetting;
use App\Models\MessageDelivery;
use App\Models\MessageTemplate;
use App\Modules\Contracts\DeliveryResult;
use App\Support\Locale\Locales;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Send one message, and write down what happened.
 *
 * Three rules hold this together:
 *
 *   **The wording is the organiser's, and the fallback is ours.** A tenant with no template for a
 *   kind, channel and language gets the platform's own, translated — never a blank message and
 *   never English at a Persian buyer. So messaging works the day an account is created.
 *
 *   **Every attempt is a row.** "Did the buyer get their confirmation" is asked at a window with
 *   somebody waiting; the answer is a record, not an inference about whether a provider was up.
 *
 *   **Sending never breaks the thing that caused it.** A confirmation that cannot be sent is a
 *   problem; an order that fails because a confirmation could not be sent is a worse one. Failures
 *   are recorded and returned, not thrown.
 */
class MessageDispatcher
{
    public const MAX_ATTEMPTS = 4;

    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Send one message on one channel.
     *
     * @param  array<string, string>  $variables  Values for the kind's declared placeholders.
     */
    public function send(
        string $kind,
        string $channelKey,
        string $recipient,
        array $variables,
        ?string $locale = null,
        array $links = [],
    ): MessageDelivery {
        $locale = Locales::normalise($locale ?? app()->getLocale());
        $channel = $this->channels->find($channelKey);

        $delivery = MessageDelivery::create([
            'tenant_id' => $this->tenants->id(),
            'kind' => $kind,
            'channel' => $channelKey,
            'recipient' => mb_substr($recipient, 0, 190),
            'locale' => $locale,
            'status' => 'queued',
            'event_id' => $links['event_id'] ?? null,
            'external_order_row_id' => $links['order_id'] ?? null,
        ]);

        if (! $channel) {
            return $this->finish($delivery, DeliveryResult::refused('channel_unavailable'), '');
        }

        $rendered = $this->render($kind, $channelKey, $locale, $variables);

        $delivery->forceFill(['preview' => mb_substr($rendered['body'], 0, 200)])->save();

        try {
            $result = $channel->send($recipient, $rendered['body'], [
                'subject' => $rendered['subject'],
                'locale' => $locale,
                'reference' => $delivery->id,
            ]);
        } catch (Throwable $e) {
            // A channel that throws is broken rather than refusing, so it is retryable — and the
            // exception is recorded rather than escaping into whatever caused the send.
            $result = DeliveryResult::unavailable($e->getMessage());
        }

        return $this->finish($delivery, $result, $rendered['body']);
    }

    /**
     * Send on every channel this organiser has turned on for this kind, to the addresses they
     * have. A buyer with no phone number simply is not sent an SMS.
     *
     * @return list<MessageDelivery>
     */
    public function announce(
        string $kind,
        array $addresses,
        array $variables,
        ?string $locale = null,
        array $links = [],
    ): array {
        $sent = [];

        foreach ($this->enabledChannels($kind) as $channelKey) {
            $channel = $this->channels->find($channelKey);
            $recipient = $channel ? ($addresses[$channel->addressKind()] ?? null) : null;

            if (! $recipient) {
                continue;
            }

            $sent[] = $this->send($kind, $channelKey, $recipient, $variables, $locale, $links);
        }

        return $sent;
    }

    /**
     * The channels a kind goes out on.
     *
     * Email is on for the messages a buyer is entitled to unless it has been turned off, because
     * an account that has configured nothing must still confirm an order. Everything else is off
     * until somebody says otherwise: a venue that connects an SMS account has not thereby asked to
     * text every buyer.
     *
     * @return list<string>
     */
    public function enabledChannels(string $kind): array
    {
        $settings = MessageChannelSetting::where('kind', $kind)->get()->keyBy('channel');
        $keys = [];

        foreach ($this->channels->all() as $key => $channel) {
            $setting = $settings->get($key);

            if ($setting) {
                $setting->enabled && $keys[] = $key;

                continue;
            }

            if ('email' === $key && ! MessageKinds::isOptional($kind)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** Try an `unavailable` delivery again. Refusals are never retried. */
    public function retry(MessageDelivery $delivery, array $variables = []): MessageDelivery
    {
        if ('unavailable' !== $delivery->status || $delivery->attempts >= self::MAX_ATTEMPTS) {
            return $delivery;
        }

        $channel = $this->channels->find($delivery->channel);

        if (! $channel) {
            return $this->finish($delivery, DeliveryResult::refused('channel_unavailable'), '');
        }

        // Rendered again from the template rather than from a stored body: the wording may have
        // been corrected since, and the corrected one is the one worth sending.
        $rendered = $this->render($delivery->kind, $delivery->channel, $delivery->locale, $variables);

        try {
            $result = $channel->send($delivery->recipient, $rendered['body'], [
                'subject' => $rendered['subject'],
                'locale' => $delivery->locale,
                'reference' => $delivery->id,
            ]);
        } catch (Throwable $e) {
            $result = DeliveryResult::unavailable($e->getMessage());
        }

        return $this->finish($delivery, $result, $rendered['body']);
    }

    /**
     * The wording for a kind, channel and language.
     *
     * @return array{subject:string, body:string}
     */
    public function render(string $kind, string $channelKey, string $locale, array $variables): array
    {
        $template = MessageTemplate::where('kind', $kind)
            ->where('channel', $channelKey)
            ->where('locale', $locale)
            ->first();

        $subject = $template?->subject ?? __($this->defaultKey($kind, 'subject'), [], $locale);
        $body = $template?->body ?? __($this->defaultKey($kind, 'body'), [], $locale);

        return [
            'subject' => MessageRenderer::render((string) $subject, $variables),
            'body' => MessageRenderer::render((string) $body, $variables),
        ];
    }

    private function defaultKey(string $kind, string $part): string
    {
        return 'messaging.defaults.'.str_replace('.', '_', $kind).'.'.$part;
    }

    private function finish(MessageDelivery $delivery, DeliveryResult $result, string $body): MessageDelivery
    {
        $delivery->forceFill([
            'status' => $result->status,
            'reference' => $result->reference,
            'reason' => $result->reason,
            'attempts' => $delivery->attempts + 1,
            'last_attempt_at' => now(),
            'preview' => $delivery->preview ?: mb_substr($body, 0, 200),
        ])->save();

        return $delivery;
    }
}
