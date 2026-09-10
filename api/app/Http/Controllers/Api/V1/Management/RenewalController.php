<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Messaging\MessageDispatcher;
use App\Domain\Renewals\Renewals;
use App\Http\Controllers\Controller;
use App\Models\EventSeries;
use App\Models\RenewalOffer;
use App\Models\RenewalRound;
use App\Models\SeasonPass;
use App\Models\Site;
use App\Support\Audit\AuditLogger;
use App\Support\Locale\Dates;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Next season, offered to last season's subscribers before anybody else.
 *
 * Opening a round is a commercial decision about who may buy which chairs, so it sits behind
 * `pricing.manage` beside the channel quotas rather than behind `events.manage`. Reading one is
 * `events.view`: the box office is asked "has Mrs Dehghani renewed?" all day and should not need a
 * permission that lets them change what anything costs to answer it.
 *
 * Sending the invitations is its own permission again — `messages.send` — because it writes to
 * several hundred people, and "who may write to our audience" is a question every account answers
 * separately from "who may set prices".
 */
class RenewalController extends Controller
{
    public function __construct(
        private readonly Renewals $renewals,
        private readonly MessageDispatcher $messages,
        private readonly AuditLogger $audit,
    ) {}

    /** Every round for one run, newest first. */
    public function index(Request $request, EventSeries $series)
    {
        $this->authorize($request, 'events.view');

        $rounds = RenewalRound::where('to_series_id', $series->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $rounds->map(fn (RenewalRound $round) => $this->renewals->forRound($round))->all(),
        ]);
    }

    /**
     * Open one, and work out who is in it.
     *
     * The answer says what could *not* be carried over as well as what could: an organiser told
     * "412 offered" and not told that 38 chairs no longer exist in the new plan will hear about
     * those 38 from the subscribers who used to sit in them.
     */
    public function open(Request $request, EventSeries $series)
    {
        $this->authorize($request, 'pricing.manage');

        $data = $request->validate([
            'from_series_id' => ['required', 'uuid'],
            'season_pass_id' => ['required', 'uuid'],
            'deadline' => ['required', 'date'],
            'name' => ['required', 'string', 'max:160'],
        ]);

        $from = EventSeries::findOrFail($data['from_series_id']);
        $pass = SeasonPass::findOrFail($data['season_pass_id']);

        $result = $this->renewals->open(
            $from,
            $series,
            $pass,
            Carbon::parse($data['deadline']),
            $data['name'],
            (string) $request->user()?->id,
        );

        $this->audit->record('renewal.opened', $result['round'], [
            'from_series_id' => $from->id,
            'offers' => $result['offered'],
            'seats' => $result['seats'],
        ]);

        return response()->json([
            'data' => $this->renewals->forRound($result['round']),
            'offered' => $result['offered'],
            'seats' => $result['seats'],
            'skipped' => $result['skipped'],
        ], 201);
    }

    /** One round, with everybody in it. */
    public function show(Request $request, RenewalRound $round)
    {
        $this->authorize($request, 'events.view');

        return response()->json(['data' => $this->renewals->forRound($round)]);
    }

    /**
     * Write to everybody who has not been told yet.
     *
     * Sent as a message about seats they hold rather than as an announcement, and so it is not
     * gated on marketing consent — a subscriber who asked never to hear about next season's
     * programme is still entitled to be told that their own chairs are being kept for them until a
     * date, and that they will lose them if they do not answer. Withholding that would be using
     * somebody's privacy choice to take their seats away.
     *
     * Idempotent by design: only offers with no `invited_at` are written to, so pressing it twice
     * does not write to anybody twice.
     */
    public function invite(Request $request, RenewalRound $round)
    {
        $this->authorize($request, 'messages.send');

        $site = Site::where('tenant_id', $round->tenant_id)->orderBy('created_at')->first();
        $base = $site ? rtrim($site->url(''), '/') : '';
        $sent = 0;

        $offers = RenewalOffer::with(['seats.seat.row', 'seats.seat.section'])
            ->where('round_id', $round->id)
            ->where('state', 'offered')
            ->whereNull('invited_at')
            ->get();

        foreach ($offers as $offer) {
            $this->messages->send('season.renewal', 'email', $offer->email, [
                'buyer' => (string) ($offer->name ?: $offer->email),
                'run' => (string) $round->name,
                'seats' => $offer->seats->map(fn ($row) => trim(
                    ($row->seat?->row?->name ?? '').' '.($row->seat?->label ?? '')
                ))->filter()->implode(', '),
                'deadline' => Dates::longWhen($round->deadline),
                'site' => (string) ($site?->name ?? ''),
                'link' => $this->renewals->linkFor($offer, $base),
            ]);

            $offer->forceFill(['invited_at' => now()])->save();
            $sent++;
        }

        $this->audit->record('renewal.invited', $round, ['sent' => $sent]);

        return response()->json(['sent' => $sent]);
    }

    /**
     * Close it: everybody who did not answer has lapsed.
     *
     * The chairs were back on general sale at the deadline whether or not anybody pressed this —
     * availability reads the date live. What this writes down is the answer to "did they say no,
     * or did they never reply?", which is a question the box office is asked in October.
     */
    public function close(Request $request, RenewalRound $round)
    {
        $this->authorize($request, 'pricing.manage');

        $this->renewals->close($round);
        $this->audit->record('renewal.closed', $round, []);

        return response()->json(['data' => $this->renewals->forRound($round->refresh())]);
    }
}
