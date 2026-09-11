<?php

namespace App\Domain\Messaging\Channels;

use App\Modules\Contracts\DeliveryResult;
use App\Modules\Contracts\MessageChannel;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email, through whatever mailer the operator configured.
 *
 * First-party and in core rather than in a module, because the credentials belong to the operator
 * running the server, not to an organiser: every other channel here is an account somebody signs
 * up for, and this one is the machine's own postbox.
 */
class EmailChannel implements MessageChannel
{
    public function key(): string
    {
        return 'email';
    }

    public function labelKey(): string
    {
        return 'messaging.channels.email';
    }

    public function addressKind(): string
    {
        return 'email';
    }

    public function send(string $to, string $body, array $options = []): DeliveryResult
    {
        $subject = (string) ($options['subject'] ?? '');
        /*
         * Whose message this is.
         *
         * Read from the tenant this send is running inside, so every channel's caller gets it
         * without passing anything: a buyer's confirmation says the venue's name, and replying to
         * it reaches the venue rather than a mailbox nobody watches.
         */
        $from = app(\App\Domain\Messaging\SenderIdentity::class)->current();

        try {
            Mail::raw($body, function (Message $message) use ($to, $subject, $from) {
                $message->to($to)->from($from['address'], $from['name']);

                if ($from['reply_to']) {
                    $message->replyTo($from['reply_to'], $from['name']);
                }

                if ('' !== $subject) {
                    $message->subject($subject);
                }
            });

            return DeliveryResult::sent();
        } catch (Throwable $e) {
            // A mail server that is down is a retry; a mail server that rejected the address is
            // not, and the transport does not reliably tell us which. Treated as retryable, and
            // the retry gives up after a few goes rather than forever.
            return DeliveryResult::unavailable($e->getMessage());
        }
    }
}
