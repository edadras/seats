<?php

namespace App\Domain\Messaging\Announcements;

use App\Domain\Audience\Segments;
use App\Domain\Privacy\Consents;
use App\Domain\Messaging\ChannelRegistry;
use App\Domain\Messaging\MessageDispatcher;
use App\Domain\Messaging\MessageRenderer;
use App\Domain\Notifications\Notifier;
use App\Models\Announcement;
use App\Models\ExternalOrder;
use App\Models\MessageDelivery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Says one thing to everybody who bought.
 *
 * Two decisions shape this.
 *
 * **The audience is worked out once, into the delivery log.** Sending goes on for as long as it
 * goes on, and orders keep arriving while it does; resolving "everybody who bought" again on each
 * batch would send some people two copies and skip others entirely, depending on where the sort
 * order moved them. So the recipients are written down at the moment the organiser presses send —
 * as ordinary queued `message_deliveries`, which is where every other message on this platform is
 * already recorded, retried and shown.
 *
 * **Only people who paid.** An announcement about tonight's performance goes to the people coming
 * to it; a cancelled order is not an audience, and a pending one is somebody who has not bought
 * yet.
 */
class AnnouncementSender
{
    /** Statuses that mean somebody is actually coming. */
    private const PAID = ['confirmed', 'partially_refunded'];

    /** How many messages one pass sends. Bounded because a channel is a network call each. */
    public const BATCH = 25;

    public const MAX_RECIPIENTS = 20000;

    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly MessageDispatcher $dispatcher,
        private readonly Notifier $notifier,
        private readonly TenantContext $tenants,
        private readonly Segments $segments,
        private readonly Consents $consents,
    ) {}

    /**
     * Who this announcement would reach, per channel, without sending anything.
     *
     * @param  array<string, mixed>|null  $rules  a saved audience's rules, where one was chosen
     * @return array{people:int, messages:int, unreachable:int}
     */
    public function preview(?string $eventId, array $channels, ?array $rules = null): array
    {
        $people = 0;
        $messages = 0;
        $unreachable = 0;
        $seen = [];
        // News or service — see `isNews`, and the class note above it.
        $news = self::isNews($eventId, $rules);

        foreach ($this->audience($eventId, $rules) as $person) {
            $counted = false;

            if ($news && ! $this->consents->allows($person->email)) {
                $unreachable++;

                continue;
            }

            foreach ($channels as $channelKey) {
                $address = $this->addressFor($person, $channelKey);

                if (! $address || isset($seen[$channelKey.'|'.$address])) {
                    continue;
                }

                $seen[$channelKey.'|'.$address] = true;
                $messages++;
                $counted = true;
            }

            $people += $counted ? 1 : 0;
        }

        return [
            'people' => $people,
            'messages' => $messages,
            // Counted and shown rather than quietly dropped: "this reaches 900 of your 4,000
            // buyers" is the sentence that makes an organiser go and ask the other 3,100.
            'unreachable' => $unreachable,
        ];
    }

    /**
     * Whether this is news, which needs a yes, or service, which does not.
     *
     * An announcement to the buyers of one event is about a booking they hold — the doors have
     * moved, bring a coat, the interval is longer tonight — and is part of having sold them the
     * ticket. An announcement to everybody, or to a saved audience, is about something they have
     * not bought. That is the line, it is drawn here, and it is the only place it is drawn.
     */
    public static function isNews(?string $eventId, ?array $rules = null): bool
    {
        return null !== $rules || ! $eventId;
    }

    /**
     * Write the queue.
     *
     * One row per person per channel they can be reached on, deduplicated by address: a buyer with
     * four orders gets one email, not four.
     */
    public function prepare(Announcement $announcement): int
    {
        $rows = [];
        $seen = [];
        $now = now();
        $people = 0;

        $news = self::isNews($announcement->event_id, $announcement->segment?->rules);

        foreach ($this->audience($announcement->event_id, $announcement->segment?->rules) as $person) {
            $reached = false;

            /*
             * Silence is not consent.
             *
             * Checked here, as the queue is written, rather than at send time: the queue is what
             * the delivery log shows an organiser, and a row for somebody who was never going to
             * be written to would be a record of a message that was never sent to a person who
             * never agreed to it.
             */
            if ($news && ! $this->consents->allows($person->email)) {
                continue;
            }

            foreach ($announcement->channels ?? [] as $channelKey) {
                $address = $this->addressFor($person, $channelKey);

                if (! $address || isset($seen[$channelKey.'|'.$address])) {
                    continue;
                }

                $seen[$channelKey.'|'.$address] = true;
                $reached = true;

                $rows[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid7(),
                    'tenant_id' => $this->tenants->idOrFail(),
                    'kind' => 'announcement',
                    'channel' => $channelKey,
                    'recipient' => mb_substr($address, 0, 190),
                    'locale' => $announcement->locale,
                    'status' => 'queued',
                    'attempts' => 0,
                    'event_id' => $announcement->event_id,
                    'announcement_id' => $announcement->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $people += $reached ? 1 : 0;

            if (count($rows) >= self::MAX_RECIPIENTS) {
                break;
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('message_deliveries')->insert($chunk);
        }

        $announcement->forceFill([
            'recipients' => $people,
            'status' => $rows ? 'sending' : 'sent',
            'started_at' => $now,
            'finished_at' => $rows ? null : $now,
        ])->save();

        return count($rows);
    }

    /**
     * Send the next few.
     *
     * Returns how many were attempted, so a caller can keep going until there is nothing left.
     * Called both from the request that pressed send — so a small announcement is done by the time
     * the screen comes back — and from the scheduled command, which finishes the long ones.
     */
    public function sendBatch(Announcement $announcement, int $limit = self::BATCH): int
    {
        $queued = MessageDelivery::where('announcement_id', $announcement->id)
            ->where('status', 'queued')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $news = self::isNews($announcement->event_id, $announcement->segment?->rules);

        foreach ($queued as $delivery) {
            $variables = [
                'buyer' => $this->nameFor($delivery->recipient),
                'event' => (string) ($announcement->event?->name ?? ''),
                'site' => (string) ($this->tenants->get()?->name ?? ''),
            ];

            $body = MessageRenderer::render((string) $announcement->body, $variables);

            /*
             * A way out, at the bottom of anything that is marketing.
             *
             * On news only: a footer offering to stop sending confirmations of bookings somebody
             * has made would be an offer this platform cannot honour. The link is signed rather
             * than stored, so no token per recipient has to be kept and guessing one is guessing
             * a signature.
             */
            if ($news) {
                $body .= "\n\n".$this->wayOut($delivery->recipient);
            }

            $this->dispatcher->sendComposed(
                $delivery,
                MessageRenderer::render((string) $announcement->subject, $variables),
                $body,
            );
        }

        $this->settle($announcement);

        return $queued->count();
    }

    /**
     * "Tell us to stop" — one line, in the reader's language, with a link that needs no sign-in.
     *
     * A person who wants out at eleven at night must not have to remember a password to get out,
     * and an organiser must not be the only route to it: the commonest way a mailing list becomes
     * a complaint is that leaving it was harder than reporting it.
     */
    private function wayOut(string $address): string
    {
        $site = \App\Models\Site::query()->orderBy('created_at')->first();
        $link = $this->consents->linkFor(
            (string) $this->tenants->idOrFail(),
            $address,
            $site?->url('/') ?? '',
        );

        return __('messaging.unsubscribeLine', ['link' => $link]);
    }

    /** Mark an announcement finished once nothing of it is still queued, and say so. */
    public function settle(Announcement $announcement): void
    {
        if ('sending' !== $announcement->status) {
            return;
        }

        $left = MessageDelivery::where('announcement_id', $announcement->id)
            ->where('status', 'queued')
            ->count();

        if ($left > 0) {
            return;
        }

        $counts = MessageDelivery::where('announcement_id', $announcement->id)
            ->selectRaw("count(*) filter (where status = 'sent') as sent, count(*) as total")
            ->first();

        $announcement->forceFill(['status' => 'sent', 'finished_at' => now()])->save();

        $this->notifier->raise('announcement.finished', [
            'sent' => (int) ($counts->sent ?? 0),
            'total' => (int) ($counts->total ?? 0),
            'subject' => (string) ($announcement->subject ?: mb_substr($announcement->body, 0, 60)),
        ], $announcement);
    }

    /* --------------------------------------------------------------------------- internals */

    /**
     * Everybody who paid, once each, with whatever addresses they left.
     *
     * The same normalisation the customer directory uses, and for the same reason: two orders from
     * one person, typed with different capitals, are one person.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function audience(?string $eventId, ?array $rules = null)
    {
        /*
         * A saved audience answers the whole question.
         *
         * Not narrowed further by the event: a segment already says who it means, and "people who
         * came last season and have not booked this one" *intersected with* "bought this one" is
         * the empty set. The screen offers the two as one choice for the same reason.
         */
        if (null !== $rules) {
            return $this->segments->resolve($rules);
        }

        return ExternalOrder::query()
            ->toBase()
            ->selectRaw(
                "lower(btrim(external_orders.buyer->>'email')) as email, ".
                "(array_agg(nullif(btrim(coalesce(external_orders.buyer->>'phone', '')), '') ".
                    'order by external_orders.created_at desc) '.
                    "filter (where nullif(btrim(coalesce(external_orders.buyer->>'phone', '')), '') is not null))[1] as phone"
            )
            ->whereIn('external_orders.status', self::PAID)
            ->whereRaw("nullif(btrim(coalesce(external_orders.buyer->>'email', '')), '') is not null")
            ->when($eventId, fn ($query) => $query->where('external_orders.event_id', $eventId))
            ->groupByRaw("lower(btrim(external_orders.buyer->>'email'))")
            ->orderByRaw("lower(btrim(external_orders.buyer->>'email'))")
            ->get();
    }

    private function addressFor(object $person, string $channelKey): ?string
    {
        $channel = $this->channels->find($channelKey);

        if (! $channel) {
            return null;
        }

        return 'phone' === $channel->addressKind()
            ? ($person->phone ?: null)
            : ($person->email ?: null);
    }

    /**
     * The name to greet this address by.
     *
     * Looked up when the message is written rather than stored on the queued row: the address is
     * already in the delivery log, and a second copy of every buyer's *name* beside it would be a
     * new place for that to leak from for the sake of one word in a greeting.
     */
    private function nameFor(string $address): string
    {
        $row = ExternalOrder::query()
            ->toBase()
            ->selectRaw("btrim(external_orders.buyer->>'name') as name")
            ->whereRaw("lower(btrim(external_orders.buyer->>'email')) = ?", [mb_strtolower($address)])
            ->orderByDesc('created_at')
            ->first();

        return (string) ($row->name ?? '');
    }
}
