<?php

namespace App\Http\Controllers\Site;

use App\Domain\Seasons\SeasonCheckout;
use App\Domain\Seasons\Seasons;
use App\Domain\Sites\Payments\GatewayRegistry;
use App\Domain\Sites\TicketMailer;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\RendersSitePages;
use App\Models\Event;
use App\Models\Hold;
use App\Models\SeasonBooking;
use App\Models\SeasonPass;
use App\Support\Locale\Dates;
use App\Support\Qr\QrRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Buying a whole run at once, on a hosted site.
 *
 * The journey is deliberately ordinary until the last step. A subscriber chooses their pass, picks
 * their seats in the same picker everybody else uses, on the first night of the run — and only
 * then does this take over, repeating that basket across the rest of the nights and charging for
 * all of them once.
 *
 * What is kept in the session is a pass id and a list of night ids, never a price and never a hold
 * token that arrived in a form: a token in a request body is a token somebody else can put there.
 */
class SeasonController extends Controller
{
    use RendersSitePages;

    private const PASS = 'seatmap_season';

    private const NIGHTS = 'seatmap_season_nights';

    /** The tokens this basket spread onto the other nights, so a reload does not spread again. */
    private const HOLDS = 'seatmap_season_holds';

    public function __construct(
        private readonly Seasons $seasons,
        private readonly SeasonCheckout $checkout,
        private readonly GatewayRegistry $gateways,
        private readonly TicketMailer $mail,
        private readonly QrRenderer $qr,
    ) {}

    /** The offer: what the run is, what it costs together, and which nights are in it. */
    public function show(Request $request, string $pass)
    {
        $site = $request->attributes->get('site');
        $season = $this->passOrFail($pass);
        $nights = $this->seasons->nightsIn($season);

        if ($nights->count() < 2) {
            // A "season" of one night is a night. Offering it would be selling a saving on nothing.
            throw new NotFoundHttpException('Not enough nights for a season ticket.');
        }

        return $this->view($site, 'site.season', [
            'title' => $season->name.' · '.$site->name,
            'pass' => $season,
            'nights' => $nights->map(fn (Event $night) => $this->nightRow($site, $night))->all(),
            'chosen' => (array) $request->session()->get(self::NIGHTS, []),
            'currency' => $season->currency,
        ]);
    }

    /**
     * Start a subscription: remember the pass, remember the nights, and go and pick the seats.
     *
     * The buyer lands in the ordinary picker on the first night they chose. Nothing about the
     * picker changes for them — the whole design of this feature is that the seat they choose
     * there is repeated, not that seat-choosing becomes a different thing.
     */
    public function begin(Request $request, string $pass)
    {
        $season = $this->passOrFail($pass);
        $nights = $this->seasons->nightsIn($season);

        $chosen = $season->isFlexible()
            ? $nights->whereIn('id', (array) $request->input('nights', []))->values()
            : $nights;

        if ($season->isFlexible() && $chosen->count() < (int) $season->nights) {
            return back()->withErrors([
                'nights' => __('site.season.pickAtLeast', ['count' => (int) $season->nights]),
            ]);
        }

        // A fresh basket. Somebody who was halfway through buying one night must not find those
        // seats silently folded into a twelve-night subscription.
        $request->session()->forget('seatmap_hold');
        $request->session()->put(self::PASS, $season->id);
        $request->session()->put(self::NIGHTS, $chosen->pluck('id')->all());
        $request->session()->forget(self::HOLDS);

        return redirect('/events/'.$chosen->first()->public_id);
    }

    /** Give up on the season and go back to buying one night. */
    public function leave(Request $request)
    {
        $this->releaseSpread($request);
        $request->session()->forget([self::PASS, self::NIGHTS, self::HOLDS]);

        return redirect('/');
    }

    /**
     * The checkout: every night, what each costs, what the pass takes off, and one total.
     *
     * The spread happens here — the first time, and only the first time. The tokens are kept in
     * the session, so a buyer who reloads the page, or comes back to it from the payment step, is
     * looking at the same held seats rather than taking a second set out of the sale.
     */
    public function checkout(Request $request)
    {
        $site = $request->attributes->get('site');
        $season = $this->currentPass($request);
        $first = $this->firstHold($request);

        if (! $season || ! $first) {
            return redirect('/')->with('seatmap_message', __('site.holdGone'));
        }

        try {
            $holds = $this->spread($request, $season, $first);
        } catch (ApiException $e) {
            // A night that could not be matched. The buyer keeps their first night's seats and is
            // told which date was the problem, in their own language.
            return redirect('/events/'.($first->event?->public_id ?? ''))
                ->with('seatmap_message', $e->localisedMessage());
        }

        $quote = $this->checkout->quote($season, $holds);

        return $this->view($site, 'site.season-checkout', [
            'title' => __('site.season.checkout').' · '.$site->name,
            'pass' => $season,
            'nights' => $this->quoteRows($site, $quote),
            'tickets' => $quote['tickets'],
            'discount' => $quote['discount'],
            'total' => $quote['total'],
            'seats' => $this->seasons->placesIn($first),
            'currency' => $season->currency,
            'expires_at' => $first->expires_at,
            'gateways' => $this->gateways->enabledFor($site),
        ]);
    }

    /** Pay for the run. */
    public function place(Request $request)
    {
        $site = $request->attributes->get('site');
        $season = $this->currentPass($request);
        $first = $this->firstHold($request);

        if (! $season || ! $first) {
            return redirect('/')->with('seatmap_message', __('site.holdGone'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'gateway' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $holds = $this->spread($request, $season, $first);
        } catch (ApiException $e) {
            return redirect('/season/checkout')->with('seatmap_message', $e->localisedMessage());
        }

        $quote = $this->checkout->quote($season, $holds);
        $allowed = array_map(fn ($gateway) => $gateway->key(), $this->gateways->enabledFor($site));

        // A run that costs nothing needs no gateway, and one that costs something needs a real one.
        if ($quote['total'] > 0 && ! in_array($data['gateway'] ?? '', $allowed, true)) {
            return back()->withInput()->withErrors(['gateway' => __('site.chooseAWayToPay')]);
        }

        $reference = SeasonCheckout::referenceFor($holds[0]);
        $buyer = ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null];

        [$booking, $intent] = $this->checkout->place(
            $site,
            $season,
            $holds,
            $buyer,
            (string) ($data['gateway'] ?? ''),
            $site->url('/season/order/'.$reference),
        );

        $request->session()->forget(['seatmap_hold', self::PASS, self::NIGHTS, self::HOLDS]);
        $request->session()->put('seatmap_season_order', $booking->reference);
        $request->session()->put('seatmap_tokens', $this->issuedTokens($booking));

        if ($booking->isConfirmed()) {
            // One email per night, because one night is what a person turns up to. They arrive
            // together and each carries the ticket for the date it names.
            foreach ($booking->orders as $order) {
                $this->mail->send($site, $order);
            }
        }

        if ($intent->redirectUrl) {
            return redirect()->away($intent->redirectUrl);
        }

        return redirect('/season/order/'.$booking->reference);
    }

    /**
     * Where a redirect gateway sends a subscriber back to.
     *
     * Nothing in the request is believed: the gateway is asked, over its own API, with the
     * reference written down when the payment began.
     */
    public function paymentReturn(Request $request, string $gateway, string $reference)
    {
        $site = $request->attributes->get('site');
        $booking = SeasonBooking::with('leadOrder')->where('reference', $reference)->first();

        if (! $booking) {
            throw new NotFoundHttpException('No such season ticket.');
        }

        $allowed = array_map(fn ($entry) => $entry->key(), $this->gateways->enabledFor($site));

        if (! in_array($gateway, $allowed, true)) {
            throw new NotFoundHttpException('No such payment method.');
        }

        $booking = $this->checkout->settle($booking, $gateway, $request->all());
        $request->session()->put('seatmap_season_order', $booking->reference);

        if ($booking->isConfirmed()) {
            $request->session()->put('seatmap_tokens', $this->issuedTokens($booking));

            foreach ($booking->orders()->get() as $order) {
                $this->mail->send($site, $order);
            }
        }

        return redirect('/season/order/'.$booking->reference);
    }

    /**
     * The confirmation, and the only place the plaintext ticket codes are shown.
     *
     * Session-guarded like the ordinary one: a reference is short and printed, so it is not a
     * secret, and a ticket token opens a door.
     */
    public function confirmation(Request $request, string $reference)
    {
        $site = $request->attributes->get('site');

        if ($request->session()->get('seatmap_season_order') !== $reference) {
            throw new NotFoundHttpException('No such season ticket.');
        }

        $booking = SeasonBooking::with(['pass', 'orders.allocations.ticket', 'orders.event.venue'])
            ->where('reference', $reference)
            ->first();

        if (! $booking) {
            throw new NotFoundHttpException('No such season ticket.');
        }

        $tokens = (array) $request->session()->get('seatmap_tokens', []);

        return $this->view($site, 'site.season-order', [
            'title' => __('site.season.yours').' · '.$site->name,
            'booking' => $booking,
            'tokens' => $tokens,
            'currency' => $booking->currency,
            'qr' => fn (string $token) => $this->qr->dataUri($token, 180),
        ]);
    }

    /* --------------------------------------------------------------------------- helpers */

    /**
     * Hold the same seats on every night, once.
     *
     * The tokens are remembered in this buyer's session. On a second call the ones still alive are
     * reused and only a night whose hold has expired is made again — a page reload must not take a
     * second set of seats out of the sale, and a hold that timed out while the buyer was reading
     * must not silently drop a night off their subscription.
     *
     * @return list<Hold>
     */
    private function spread(Request $request, SeasonPass $pass, Hold $first): array
    {
        $kept = Hold::whereIn('token', (array) $request->session()->get(self::HOLDS, []))
            ->with('event')
            ->get()
            ->filter(fn (Hold $hold) => $hold->isActive());

        $nights = $request->session()->get(self::NIGHTS, []);
        $wanted = count($nights) ?: $this->seasons->nightsIn($pass)->count();

        if ($kept->count() + 1 === $wanted && $kept->every(fn (Hold $hold) => $hold->event_id !== $first->event_id)) {
            return array_values(collect([$first, ...$kept])
                ->sortBy(fn (Hold $hold) => $hold->event?->starts_at?->getTimestamp() ?? 0)
                ->all());
        }

        // Something has expired, or this is the first time. Whatever is left of the old spread is
        // released before a new one is made, or the buyer's own seats would block their own basket.
        $this->releaseSpread($request);

        $holds = $this->seasons->spread(
            $pass,
            $first,
            (array) $nights,
            (string) $request->session()->getId(),
            $request->ip(),
        );

        $request->session()->put(self::HOLDS, array_values(array_map(
            fn (Hold $hold) => $hold->token,
            array_filter($holds, fn (Hold $hold) => $hold->id !== $first->id),
        )));

        return $holds;
    }

    private function releaseSpread(Request $request): void
    {
        $tokens = (array) $request->session()->get(self::HOLDS, []);

        if ([] === $tokens) {
            return;
        }

        foreach (Hold::whereIn('token', $tokens)->get() as $hold) {
            if ($hold->isActive()) {
                app(\App\Domain\Inventory\HoldService::class)->release($hold);
            }
        }

        $request->session()->forget(self::HOLDS);
    }

    private function passOrFail(string $id): SeasonPass
    {
        $pass = SeasonPass::find($id);

        if (! $pass || ! $pass->isLive()) {
            throw new NotFoundHttpException('No such season ticket.');
        }

        return $pass;
    }

    private function currentPass(Request $request): ?SeasonPass
    {
        $id = $request->session()->get(self::PASS);

        if (! $id) {
            return null;
        }

        $pass = SeasonPass::find($id);

        return $pass && $pass->isLive() ? $pass : null;
    }

    private function firstHold(Request $request): ?Hold
    {
        $token = $request->session()->get('seatmap_hold');

        if (! $token) {
            return null;
        }

        $hold = Hold::with('event')->where('token', $token)->first();

        return $hold && $hold->isActive() ? $hold : null;
    }

    /** @return array<string, mixed> */
    private function nightRow($site, Event $night): array
    {
        $starts = $night->starts_at?->setTimezone($night->timezone ?: $site->timezone);

        return [
            'id' => $night->id,
            'name' => $night->name,
            'when' => Dates::longWhen($starts, app()->getLocale()),
            'url' => '/events/'.$night->public_id,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function quoteRows($site, array $quote): array
    {
        return array_map(function (array $night) use ($site) {
            $event = $night['event'];
            $starts = $event?->starts_at?->setTimezone($event->timezone ?: $site->timezone);

            return [
                'name' => $event?->name,
                'when' => Dates::longWhen($starts, app()->getLocale()),
                'tickets' => $night['tickets'],
                'discount' => $night['discount'],
                'total' => $night['totals']->total,
                'extras' => $night['totals']->extraLines(),
            ];
        }, $quote['nights']);
    }

    /** @return array<string, string> allocation id => plaintext token */
    private function issuedTokens(SeasonBooking $booking): array
    {
        $tokens = [];

        // The relation when it is loaded, because a freshly confirmed booking carries the models
        // that hold the plaintext codes; a re-query would find tickets with only their hashes.
        $orders = $booking->relationLoaded('orders')
            ? $booking->orders
            : $booking->orders()->with('allocations.ticket')->get();

        foreach ($orders as $order) {
            foreach ($order->allocations as $allocation) {
                if ($allocation->ticket?->plainToken) {
                    $tokens[$allocation->id] = $allocation->ticket->plainToken;
                }
            }
        }

        return $tokens;
    }
}
