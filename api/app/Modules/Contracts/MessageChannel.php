<?php

namespace App\Modules\Contracts;

/**
 * A way to reach a buyer: SMS, a messenger, email.
 *
 * Deliberately narrow. A channel is handed a recipient, a rendered message and a locale, and says
 * whether it went. It is not told what the message is *for*, because a channel that knew it was
 * sending a ticket would grow an opinion about tickets, and then there would be two places that
 * decide what a ticket says.
 */
interface MessageChannel
{
    public function key(): string;

    /** Translation key for the name an organiser picks from a list. */
    public function labelKey(): string;

    /**
     * What a recipient looks like for this channel, so the platform can validate one before
     * queueing a message nobody will receive: `email`, `phone`, or `handle`.
     */
    public function addressKind(): string;

    /**
     * Send one message.
     *
     * Returns a result rather than throwing on refusal: a provider saying "that number is not a
     * mobile" is an answer, and the delivery log needs to record it as one. Throwing is reserved
     * for the channel itself being broken.
     *
     * @param  string  $to       A recipient of the kind addressKind() names.
     * @param  string  $body     Already rendered and already translated.
     * @param  array{subject?:string, locale?:string, reference?:string}  $options
     */
    public function send(string $to, string $body, array $options = []): DeliveryResult;
}
