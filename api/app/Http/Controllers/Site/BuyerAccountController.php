<?php

namespace App\Http\Controllers\Site;

use App\Domain\Loyalty\Loyalty;
use App\Domain\Orders\TicketIssuer;
use App\Exceptions\ApiException;
use App\Domain\Refunds\RefundPolicy;
use App\Domain\Refunds\RefundRequests;
use App\Domain\Orders\TicketTransfers;
use App\Domain\Sites\Auth\GoogleIdentity;
use App\Domain\Sites\Themes;
use App\Domain\Wallet\Wallets;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\RendersSitePages;
use App\Models\ExternalOrder;
use App\Models\RefundRequest;
use App\Models\Site;
use App\Support\Locale\Money;
use App\Support\Pdf\TicketPdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A buyer's own page on an organiser's site: everything they have bought, and their tickets back.
 *
 * There is no buyer account in the database and there does not need to be one. Signing in with
 * Google proves an email address; orders already carry the address they were bought with, so the
 * address *is* the account. That keeps this feature from becoming a second copy of every buyer's
 * name and email, sitting beside the orders and drifting from them.
 *
 * What the session holds after signing in is exactly that: an address Google said it had verified,
 * and a display name. Every query below is `this tenant's orders whose buyer email is that one`.
 */
class BuyerAccountController extends Controller
{
    use RendersSitePages;

    private const SESSION = 'seatmap_buyer';

    public function __construct(
        private readonly GoogleIdentity $google,
        private readonly TicketIssuer $tickets,
    ) {}

    /** The page: their orders when they are signed in, the button when they are not. */
    public function show(Request $request)
    {
        $site = $request->attributes->get('site');
        $buyer = $this->signedIn($request);

        return $this->view($site, 'site.account', [
            'title' => __('site.account.title').' · '.$site->name,
            'buyer' => $buyer,
            // Their standing, where this venue keeps one and they are signed in to have one.
            'points' => $buyer ? $this->pointsFor($buyer['email']) : null,
            'orders' => $buyer ? $this->orders($site, $buyer['email']) : [],
            'canSignIn' => $this->available($site),
            'wallets' => app(Wallets::class)->offered(),
            'notice' => $request->query('signin'),
        ]);
    }

    /**
     * Turn points into credit to spend here.
     *
     * The amount is never in the request: the buyer says how many points, and what those are worth
     * is the organiser's rate rather than anything a browser can name. What comes back is an
     * ordinary credit note against their address, which the checkout already knows how to spend.
     */
    public function redeemPoints(Request $request)
    {
        $buyer = $this->signedIn($request);

        if (! $buyer) {
            return redirect('/account?signin=expired');
        }

        $data = $request->validate(['points' => ['required', 'integer', 'min:1', 'max:10000000']]);

        try {
            $voucher = app(Loyalty::class)->redeem($buyer['email'], (int) $data['points']);
        } catch (ApiException $e) {
            return redirect('/account')->with('seatmap_message', $e->localisedMessage());
        }

        return redirect('/account')->with('seatmap_message', __('site.points.turned', [
            'amount' => Money::format((int) $voucher->amount, (string) $voucher->currency),
        ]));
    }

    /**
     * What this address has, and where that leaves them.
     *
     * Null where the venue runs no scheme, so the page renders exactly as it did before this
     * existed rather than showing somebody a nought they cannot do anything about.
     */
    private function pointsFor(string $email): ?array
    {
        $loyalty = app(Loyalty::class);
        $programme = $loyalty->programme();

        if (! $programme || ! $programme->isLive()) {
            return null;
        }

        $balance = $loyalty->balance($email);
        $unit = 10 ** Money::exponent($programme->currency);
        $units = $programme->points_per_unit > 0 ? intdiv($balance, $programme->points_per_unit) : 0;

        return [
            'name' => $programme->name,
            'balance' => $balance,
            'standing' => $loyalty->standing($email, $programme),
            'min_redeem' => $programme->min_redeem,
            // What the whole balance is worth right now, so the offer is a sentence rather than a
            // sum somebody has to do themselves.
            'worth' => Money::format($units * $unit, $programme->currency),
            'can_redeem' => $balance >= max(1, $programme->min_redeem) && $units > 0,
            'redeemable' => $units * $programme->points_per_unit,
        ];
    }

    /** Off to Google. */
    public function start(Request $request)
    {
        $site = $request->attributes->get('site');

        $this->assertAvailable($site);

        return redirect()->away(
            $this->google->beginUrl($site, $this->google->safePath($request->query('return')))
        );
    }

    /**
     * Back from Google, by way of the platform's own host.
     *
     * The token is the only thing believed here, and it is believed because this application put
     * it in the cache sixty seconds ago against this very site. Nothing about the buyer travels in
     * the URL.
     */
    public function finish(Request $request)
    {
        $site = $request->attributes->get('site');

        $this->assertAvailable($site);

        $person = $this->google->claimHandoff($site, (string) $request->query('token'));

        if (! $person) {
            return redirect('/account?signin=expired');
        }

        // A new session id for a new person: whoever was using this browser before must not
        // inherit the session that is now signed in as somebody.
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, $person);

        return redirect($this->google->safePath($request->query('return')));
    }

    public function signOut(Request $request)
    {
        $request->session()->forget(self::SESSION);
        $request->session()->regenerate();

        return redirect('/');
    }

    /**
     * Their tickets, with fresh codes.
     *
     * A POST, and deliberately not a link: reissuing is a change, not a view. The code that was
     * emailed stops working the moment this runs — the platform keeps a hash of it and cannot
     * hand back what it does not have — so the page says so before the button is pressed, and a
     * browser prefetching a link must never be able to invalidate somebody's ticket.
     */
    /**
     * The same booking, into a phone's wallet.
     *
     * A reissue, like the PDF beside it and for the same reason: the platform keeps a hash of the
     * code it emailed and cannot hand that code back, so the only way to put a working ticket in a
     * wallet later is to make a new one. The warning is on the button.
     */
    public function wallet(Request $request, string $reference, string $platform)
    {
        $site = $request->attributes->get('site');
        $buyer = $this->signedIn($request);

        if (! $buyer) {
            throw new NotFoundHttpException('Not signed in.');
        }

        $order = $this->ownOrder($buyer['email'], $reference);
        $tokens = $this->reissueAll($order);
        $wallets = app(Wallets::class);
        $fresh = $order->fresh(['allocations.ticket', 'event.venue']);

        if ('google' === $platform) {
            return redirect()->away($wallets->google($site, $fresh, $tokens));
        }

        $pass = $wallets->apple($site, $fresh, $tokens);

        return response($pass['body'], 200, [
            'Content-Type' => $pass['type'],
            'Content-Disposition' => 'attachment; filename="'.$pass['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function tickets(Request $request, string $reference)
    {
        $site = $request->attributes->get('site');
        $buyer = $this->signedIn($request);

        if (! $buyer) {
            throw new NotFoundHttpException('Not signed in.');
        }

        $order = $this->ownOrder($buyer['email'], $reference);
        $tokens = $this->reissueAll($order);

        $pdf = app(TicketPdf::class)->render($site, $order->fresh(['allocations.ticket', 'event.venue']), $tokens);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="tickets-'.$reference.'.pdf"',
            // The codes in here open a door. Nothing between us and the buyer keeps a copy.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /* --------------------------------------------------------------------------- helpers */

    /** @return array{email:string, name:?string}|null */
    private function signedIn(Request $request): ?array
    {
        $buyer = $request->session()->get(self::SESSION);

        return is_array($buyer) && ! empty($buyer['email']) ? $buyer : null;
    }

    private function available(Site $site): bool
    {
        return $site->offersSignIn();
    }

    private function assertAvailable(Site $site): void
    {
        if (! $this->available($site)) {
            // A site that does not offer signing in has no sign-in pages either, rather than
            // pages that explain what it will not do.
            throw new NotFoundHttpException('Signing in is not offered here.');
        }
    }

    /** Every order this address bought from this organiser, newest first. */
    /**
     * Give one ticket to somebody else.
     *
     * A POST, and behind the signed-in session, because it kills a working code and mints another:
     * a link a mail client could prefetch must never be able to do that.
     */
    public function transfer(Request $request, string $reference)
    {
        $site = $request->attributes->get('site');
        $buyer = $this->signedIn($request);

        if (! $buyer) {
            throw new NotFoundHttpException('Not signed in.');
        }

        $data = $request->validate([
            'allocation_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
        ]);

        $order = $this->ownOrder($buyer['email'], $reference);

        $allocation = $order->allocations->firstWhere('id', $data['allocation_id']);

        if (! $allocation) {
            throw new NotFoundHttpException('No such ticket on this booking.');
        }

        app(TicketTransfers::class)->give(
            $site,
            $order,
            $allocation,
            ['name' => $data['name'], 'email' => $data['email']],
            ['name' => $buyer['name'] ?? null, 'email' => $buyer['email']],
        );

        return redirect('/account')->with('seatmap_message', __('site.transfer.done', [
            'name' => $data['name'],
        ]));
    }

    /**
     * "Can I have my money back?"
     *
     * Inside the organiser's own terms it is granted at once — they already said yes when they
     * wrote them, and making a buyer wait for a human to repeat that answer is a queue for
     * nothing. Outside them it becomes a request the box office sees, because "no" is not always
     * the right answer: somebody in hospital is a conversation, not a policy.
     */
    public function refund(Request $request, string $reference)
    {
        $buyer = $this->signedIn($request);

        if (! $buyer) {
            throw new NotFoundHttpException('Not signed in.');
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'allocation_ids' => ['sometimes', 'array'],
            'allocation_ids.*' => ['uuid'],
        ]);

        $order = $this->ownOrder($buyer['email'], $reference);

        $asked = app(RefundRequests::class)->ask(
            $order,
            // Only seats that are actually on this booking: a buyer editing the form cannot ask
            // for somebody else's chair back.
            array_values(array_intersect(
                $data['allocation_ids'] ?? [],
                $order->allocations->pluck('id')->all(),
            )),
            $data['reason'] ?? '',
        );

        return redirect('/account')->with('seatmap_message', __(
            'approved' === $asked->status ? 'site.refunds.done' : 'site.refunds.asked'
        ));
    }

    /**
     * Offer a seat back to the public at what it cost.
     *
     * Nothing moves when they press this: the seat stays theirs until somebody else actually buys
     * it, and they may take it off sale again at any time up to that moment. A listing that took
     * the ticket away while it sat unsold would be a worse deal than a refund.
     */
    public function resell(Request $request, string $reference)
    {
        $buyer = $this->signedIn($request);

        if (! $buyer) {
            throw new NotFoundHttpException('Not signed in.');
        }

        $data = $request->validate([
            'allocation_ids' => ['required', 'array', 'min:1'],
            'allocation_ids.*' => ['uuid'],
        ]);

        $order = $this->ownOrder($buyer['email'], $reference);
        $resales = app(\App\Domain\Resale\Resales::class);
        $listed = 0;

        foreach ($order->allocations as $allocation) {
            if (! in_array($allocation->id, $data['allocation_ids'], true)) {
                continue;
            }

            try {
                $resales->list($allocation, $buyer['email'], $buyer['name'] ?? null);
                $listed++;
            } catch (ApiException $e) {
                // One seat that cannot go back — already scanned, say — must not stop the others.
                // The count below is what the buyer is told, and it is the truth about what moved.
                continue;
            }
        }

        $order->event?->bumpAvailabilityVersion();

        return redirect('/account')->with('seatmap_message', $listed
            ? trans_choice('site.resale.listed', $listed, ['count' => $listed])
            : __('site.resale.refused'));
    }

    /** Take it off sale again. Theirs the whole time, and theirs still. */
    public function unresell(Request $request, string $reference)
    {
        $buyer = $this->signedIn($request);

        if (! $buyer) {
            throw new NotFoundHttpException('Not signed in.');
        }

        $order = $this->ownOrder($buyer['email'], $reference);
        $resales = app(\App\Domain\Resale\Resales::class);
        $refusal = null;
        $taken = 0;

        foreach (\App\Models\ResaleListing::where('state', 'open')
            ->whereIn('allocation_id', $order->allocations->pluck('id'))->get() as $listing) {
            try {
                $resales->withdraw($listing);
                $taken++;
            } catch (ApiException $e) {
                // Somebody is at the checkout with that seat. The others still come down, and the
                // reason for the one that did not is what the page says.
                $refusal = $e->localisedMessage();
            }
        }

        return redirect('/account')->with(
            'seatmap_message',
            $refusal && ! $taken ? $refusal : __('site.resale.withdrawn'),
        );
    }

    /**
     * Move this booking to another night.
     *
     * The old seats are not given back here. They are given back at the checkout, when the new
     * ones are already held and about to be paid for — which is the whole reason this exists
     * rather than "refund, then buy again". What this does is put the intention in the session and
     * send the buyer to choose.
     */
    public function exchange(Request $request, string $reference)
    {
        $buyer = $this->signedIn($request);

        if (! $buyer) {
            throw new NotFoundHttpException('Not signed in.');
        }

        $data = $request->validate([
            'allocation_ids' => ['sometimes', 'array'],
            'allocation_ids.*' => ['uuid'],
        ]);

        $order = $this->ownOrder($buyer['email'], $reference);
        $terms = app(\App\Domain\Orders\Exchanges::class)->check($order);

        if (! $terms['allowed']) {
            return redirect('/account')->with('seatmap_message', __('site.exchange.closed'));
        }

        $ids = array_values(array_intersect(
            $data['allocation_ids'] ?? $order->allocations->pluck('id')->all(),
            $order->allocations->where('status', 'active')->pluck('id')->all(),
        ));

        if ([] === $ids) {
            return redirect('/account')->with('seatmap_message', __('site.exchange.closed'));
        }

        /*
         * Held in the session, not written down.
         *
         * An exchange that was recorded the moment somebody clicked "move this" would be a booking
         * in a half-state for however long they browsed — and a buyer who closed the tab would
         * come back to a ticket that was neither given back nor still theirs.
         */
        $request->session()->put('seatmap_exchange', [
            'reference' => $order->external_order_id,
            'allocation_ids' => $ids,
            'event_id' => $order->event_id,
        ]);

        return redirect('/events/'.($order->event?->public_id ?? ''))
            ->with('seatmap_message', __('site.exchange.chooseSeats'));
    }

    /**
     * New codes for every ticket on a booking.
     *
     * Shared by the PDF and the wallet passes, because they are the same act: the old codes stop
     * working the moment these exist, and doing that twice in two places is doing it twice.
     *
     * @return array<string, string> allocation id => plaintext code
     */
    private function reissueAll(ExternalOrder $order): array
    {
        $tokens = [];

        foreach ($order->allocations as $allocation) {
            $ticket = $this->tickets->reissue($allocation);

            if ($ticket?->plainToken) {
                $tokens[$allocation->id] = $ticket->plainToken;
            }
        }

        return $tokens;
    }

    private function orders(Site $site, string $email): array
    {
        return ExternalOrder::query()
            ->with(['allocations.ticket', 'event'])
            ->whereRaw("lower(btrim(external_orders.buyer->>'email')) = ?", [$email])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (ExternalOrder $order) => [
                'reference' => $order->external_order_id,
                'status' => $order->status,
                // Whether they may ask, in the organiser's own terms — and whether they already
                // have, because a second form under a booking already waiting on an answer is a
                // form that gets filled in twice.
                'refundable' => app(RefundPolicy::class)->check($order)['allowed'],
                'refund_terms' => $order->event
                    ? app(RefundPolicy::class)->sentence($order->event)
                    : null,
                'refund_asked' => RefundRequest::where('external_order_row_id', $order->id)
                    ->where('status', 'pending')
                    ->exists(),
                // The two other things to do with a ticket you cannot use. Offered only where the
                // organiser said so, because a button that leads to a refusal is a worse answer
                // than no button.
                'exchangeable' => app(\App\Domain\Orders\Exchanges::class)->check($order)['allowed'],
                // Named seats only: a standing ticket is a right to come in rather than a
                // particular chair, so there is nothing to hand to one buyer instead of another.
                'resellable' => (bool) ($order->event?->resale)
                    && in_array($order->status, ['confirmed', 'partially_refunded'], true)
                    && $order->allocations->contains(
                        fn ($allocation) => 'active' === $allocation->status && $allocation->seat_id
                    ),
                // Seats of this booking currently offered back to the public, so somebody can see
                // what they have put up and take it down again.
                'listed' => \App\Models\ResaleListing::where('state', 'open')
                    ->whereIn('allocation_id', $order->allocations->pluck('id'))
                    ->count(),
                // Seats of this booking that have already changed hands. Said on the page, because
                // otherwise a seller sees a refunded booking with a void ticket and no explanation
                // of where their seat went.
                'resold' => \App\Models\ResaleListing::where('state', 'sold')
                    ->whereIn('allocation_id', $order->allocations->pluck('id'))
                    ->count(),
                'placed_at' => $order->created_at,
                'total' => Money::format((int) $order->total_amount, (string) $order->currency),
                'event' => $order->event,
                'lines' => $order->allocations->map(fn ($allocation) => [
                    'id' => $allocation->id,
                    'seat' => trim(implode(' · ', array_filter([
                        $allocation->section_name, $allocation->row_name,
                        $allocation->seat_id ? $allocation->seat_label : null,
                    ]))),
                    'quantity' => $allocation->seat_id ? 1 : (int) ($allocation->quantity ?: 1),
                    // Whether this line is a named chair or a right to come in. The difference
                    // decides what may be done with it — a standing ticket cannot be resold.
                    'seated' => (bool) $allocation->seat_id,
                    // When they were told to arrive, on a timed-entry booking.
                    'entry' => \App\Domain\Events\EntrySlots::window(
                        $allocation->entry_starts_at,
                        $allocation->entry_ends_at,
                        $order->event?->timezone,
                    ),
                    'used' => (bool) $allocation->ticket?->used_at,
                    // Who is holding it now, when that is no longer the person who bought it.
                    'holder' => $allocation->ticket?->holder_name,
                    // A used ticket cannot be given away — somebody is already inside on it — and
                    // a voided one is not a ticket.
                    'transferable' => 'issued' === $allocation->ticket?->status,
                ])->all(),
                // Nothing to reissue once every ticket on the order has been scanned or voided.
                'reissuable' => $order->allocations->contains(
                    fn ($allocation) => 'issued' === $allocation->ticket?->status
                ),
            ])
            ->all();
    }

    private function ownOrder(string $email, string $reference): ExternalOrder
    {
        $order = ExternalOrder::with(['allocations.ticket', 'event.venue'])
            ->where('external_order_id', $reference)
            ->whereRaw("lower(btrim(external_orders.buyer->>'email')) = ?", [$email])
            ->first();

        if (! $order) {
            // Not "that is not yours": a reference somebody else's order carries should look
            // exactly like a reference nobody's order carries.
            throw new NotFoundHttpException('No such order.');
        }

        return $order;
    }

}
