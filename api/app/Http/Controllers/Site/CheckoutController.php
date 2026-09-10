<?php

namespace App\Http\Controllers\Site;

use App\Domain\Addons\Addons;
use App\Domain\Addons\Donations;
use App\Domain\Discounts\DiscountOffer;
use App\Domain\Discounts\Discounts;
use App\Domain\Invoicing\InvoiceIssuer;
use App\Domain\Questions\CheckoutQuestions;
use App\Domain\Sites\Payments\GatewayRegistry;
use App\Domain\Sites\StorefrontCheckout;
use App\Domain\Sites\Themes;
use App\Exceptions\ApiException;
use App\Domain\Sites\TicketMailer;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\RendersSitePages;
use App\Models\ExternalOrder;
use App\Models\Hold;
use App\Models\Site;
use App\Support\Locale\Money;
use App\Support\Pdf\InvoicePdf;
use App\Domain\Wallet\Wallets;
use App\Support\Pdf\TicketPdf;
use App\Support\Qr\QrRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Checkout and the order confirmation, on a hosted site.
 *
 * The buyer's hold is read from their session, never from the request body: a hold token in a form
 * field is a hold token someone else can put there.
 */
class CheckoutController extends Controller
{
    use RendersSitePages;

    public function __construct(
        private readonly StorefrontCheckout $checkout,
        private readonly GatewayRegistry $gateways,
        private readonly TicketMailer $mail,
        private readonly QrRenderer $qr,
        private readonly Discounts $discounts,
    ) {}

    public function show(Request $request)
    {
        $site = $request->attributes->get('site');
        $hold = $this->heldSeats($request);

        if (! $hold) {
            return redirect('/')->with('seatmap_message', __('site.holdGone'));
        }

        $snapshot = $hold->price_snapshot['decoded'] ?? [];

        // Re-asked on every render rather than remembered: a code that has run out, been paused or
        // expired since the buyer typed it must stop taking money off before they pay, not after.
        $offer = $this->offerFor($request, $hold);
        $error = $this->discountError($request);

        if ($offer && ! $offer->isAllowed()) {
            // It worked when they typed it and does not now. Drop it and say why, rather than
            // showing a reduced total that the payment step would quietly disagree with.
            $request->session()->forget('seatmap_discount');
            $error = $error ?: __('site.discount.refused.'.$offer->reason);
            $offer = null;
        }

        $off = $offer ? $offer->amount : 0;
        // The same arithmetic the payment will use, asked of the same code. A summary that adds
        // up differently from the charge is the one bug a checkout must not have.
        $totals = StorefrontCheckout::totalsFor($hold, $off);

        return $this->view($site, 'site.checkout', [
            'title' => 'Checkout · '.$site->name,
            'hold' => $hold,
            'lines' => $this->lines($snapshot),
            // The window they chose, read back from the signed snapshot rather than looked up
            // again: it is part of what was reserved, and the page must show what was reserved.
            'entry' => $snapshot['entry'] ?? null,
            'subtotal' => (int) $hold->total_amount,
            'discount' => $offer ? [
                'code' => $offer->code->code,
                'amount' => $off,
            ] : null,
            'discountError' => $error,
            'extras' => $totals->extraLines(),
            'total' => $totals->total,
            // What else is for sale, and what is left of it. Offered before the money moves, so a
            // buyer chooses a programme in the same breath as their seats.
            'addons' => app(Addons::class)->offer($hold->event, count($this->places($hold))),
            'addonError' => $request->session()->get('seatmap_addon_error'),
            'donation' => app(Donations::class)->prompt($hold->event),
            'currency' => $hold->currency,
            'expires_at' => $hold->expires_at,
            'gateways' => $this->gateways->enabledFor($site),
            // Only asked for where an invoice can actually be issued. A company address field on a
            // site that cannot produce the document is a question with no purpose.
            'invoices' => $site->offersInvoices(),
            // The organiser's own questions, expanded per seat where they are asked per seat.
            'questions' => app(CheckoutQuestions::class)->fields($hold->event, $this->places($hold)),
        ]);
    }

    /**
     * One entry per ticket being bought, named the way a person would name it.
     *
     * A named seat is its own place; a standing area sold four at a time is four places, numbered,
     * because "guest 2 of 4 in the pit" is a thing somebody at a door has to be able to find.
     *
     * @return list<array{key: string, label: string}>
     */
    private function places(Hold $hold): array
    {
        $snapshot = $hold->price_snapshot['decoded'] ?? [];
        $places = [];

        foreach ($snapshot['seats'] ?? [] as $seat) {
            $places[] = [
                'key' => (string) $seat['seat_id'],
                'label' => trim(implode(' · ', array_filter([
                    $seat['section'] ?? null, $seat['row'] ?? null, $seat['label'] ?? null,
                ]))),
            ];
        }

        foreach ($snapshot['areas'] ?? [] as $index => $area) {
            for ($n = 1; $n <= max(1, (int) ($area['quantity'] ?? 1)); $n++) {
                $places[] = [
                    'key' => 'a'.$index.'p'.$n,
                    'label' => ($area['label'] ?? __('site.standing')).' · '.Money::number($n),
                ];
            }
        }

        return $places;
    }

    /**
     * Which allocation each place turned into, once the order exists.
     *
     * Standing places share an allocation — four in the pit is one row of quantity four — so the
     * same allocation appears against several place keys, which is right: four guests, one line.
     *
     * @return array<string, string>
     */
    private function placeAllocations(Hold $hold, ExternalOrder $order): array
    {
        $order->loadMissing('allocations');
        $map = [];
        $areas = $order->allocations->whereNull('seat_id')->values();

        foreach ($order->allocations as $allocation) {
            if ($allocation->seat_id) {
                $map[(string) $allocation->seat_id] = $allocation->id;
            }
        }

        $snapshot = $hold->price_snapshot['decoded'] ?? [];

        foreach ($snapshot['areas'] ?? [] as $index => $area) {
            $allocation = $areas[$index] ?? null;

            if (! $allocation) {
                continue;
            }

            for ($n = 1; $n <= max(1, (int) ($area['quantity'] ?? 1)); $n++) {
                $map['a'.$index.'p'.$n] = $allocation->id;
            }
        }

        return $map;
    }

    /**
     * Try a code the buyer typed.
     *
     * Nothing is written here — a discount is only spent when there is an order to spend it on.
     * What is kept is the spelling, in this buyer's own session, so the code cannot be put on
     * somebody else's basket by a link.
     */
    public function applyDiscount(Request $request)
    {
        $hold = $this->heldSeats($request);

        if (! $hold) {
            return redirect('/')->with('seatmap_message', __('site.holdGone'));
        }

        $typed = (string) $request->input('code', '');
        $offer = $this->offer($hold, $typed);

        if (! $offer->isAllowed()) {
            $request->session()->forget('seatmap_discount');

            return redirect('/checkout')->with('seatmap_discount_error', $offer->reason);
        }

        $request->session()->put('seatmap_discount', $offer->code->code);

        return redirect('/checkout');
    }

    public function removeDiscount(Request $request)
    {
        $request->session()->forget('seatmap_discount');

        return redirect('/checkout');
    }

    /**
     * Adopt a hold made somewhere else and go and pay for it.
     *
     * This is how a website with no server of its own sells a ticket: the widget on that page
     * makes the hold against the public embed API, and hands the buyer here to pay. Nothing about
     * the price travels in the URL — only the hold's token, which this application issued, and
     * which it prices itself.
     *
     * A token for another organiser's hold does not resolve: `Hold` is tenant-scoped and this
     * request's tenant came from the Host. An expired one is not adopted either, because the seats
     * behind it are already back on sale.
     */
    public function resume(Request $request)
    {
        $token = (string) $request->query('hold');
        $hold = $token ? Hold::with('event')->where('token', $token)->first() : null;

        if (! $hold || ! $hold->isActive()) {
            return redirect('/')->with('seatmap_message', __('site.holdGone'));
        }

        // A new session id: whoever pressed "reserve" on somebody's website is starting a purchase
        // here, and inheriting whatever this browser was doing on this domain before is not it.
        $request->session()->regenerate();
        $request->session()->put('seatmap_hold', $hold->token);

        return redirect('/checkout');
    }

    /**
     * What the booking would come to, with these extras chosen.
     *
     * The summary on the page is server-rendered, and add-ons are picked after it is rendered. A
     * total worked out in the browser would be a second implementation of the arithmetic, and the
     * one bug a checkout must not have is a summary that disagrees with the charge — so the page
     * asks, and the answer comes from the same `totalsFor` the payment uses.
     */
    public function quote(Request $request)
    {
        $hold = $this->heldSeats($request);

        if (! $hold) {
            return response()->json(['error' => 'hold_gone'], 410);
        }

        $data = $request->validate([
            'addons' => ['sometimes', 'array', 'max:40'],
            'addons.*' => ['integer', 'min:0', 'max:999'],
            'donation' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $offer = $this->offerFor($request, $hold);
        $places = count($this->places($hold));

        try {
            $lines = app(Addons::class)->price($hold->event, $data['addons'] ?? [], $places);
        } catch (ApiException $e) {
            // A quantity the organiser does not allow. Answered rather than thrown, because this
            // is a keystroke and not a purchase.
            return response()->json(['error' => $e->errorCode(), 'message' => $e->localisedMessage()], 422);
        }

        $totals = StorefrontCheckout::totalsFor(
            $hold,
            $offer?->isAllowed() ? $offer->amount : 0,
            app(Addons::class)->total($lines),
            app(Donations::class)->amount($hold->event, $data['donation'] ?? 0),
        );

        $money = fn (int $minor) => Money::format($minor, (string) $hold->currency, app()->getLocale());

        return response()->json([
            'extras' => array_map(fn (array $line) => $line + ['formatted' => $money($line['amount'])],
                $totals->extraLines()),
            'total' => $totals->total,
            'total_formatted' => $money($totals->total),
        ]);
    }

    public function place(Request $request)
    {
        $site = $request->attributes->get('site');
        $hold = $this->heldSeats($request);

        if (! $hold) {
            return redirect('/')->with('seatmap_message', __('site.holdGone'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'gateway' => ['required', 'string', 'max:40'],
            // Asked for only when the buyer says they need an invoice, and kept only then.
            'invoice' => ['sometimes', 'boolean'],
            'company' => ['nullable', 'string', 'max:160'],
            'tax_number' => ['nullable', 'string', 'max:60'],
            'billing_address' => ['nullable', 'string', 'max:400'],
            // What they want beside the tickets, and what they want to give. Both quantities and
            // amounts only — every price comes from the organiser's own rows.
            'addons' => ['sometimes', 'array', 'max:40'],
            'addons.*' => ['integer', 'min:0', 'max:999'],
            'donation' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $allowed = array_map(fn ($g) => $g->key(), $this->gateways->enabledFor($site));

        if (! in_array($data['gateway'], $allowed, true)) {
            return back()->withInput()->withErrors(['gateway' => 'Choose a way to pay.']);
        }

        // The gateway may need somewhere to send the buyer back to, and it needs it before the
        // order exists — so the reference is derived from the hold, the same way the order id is.
        $reference = $this->reference($hold);

        // Priced again, here, a moment before the money moves. Everything between the buyer
        // typing the code and pressing pay is time in which it could have run out.
        $offer = $this->offerFor($request, $hold);

        /*
         * The organiser's own questions, checked before the money moves.
         *
         * A required answer left blank has to stop a purchase — otherwise it is collected on a page
         * nobody comes back to — and it has to stop it before the gateway is involved rather than
         * after, when the seats are sold and the answer is still missing.
         */
        $questions = app(CheckoutQuestions::class);
        $answers = $questions->validate($hold->event, $this->places($hold), $request->all());

        $billing = ($site->offersInvoices() && $request->boolean('invoice')) ? array_filter([
            'company' => $data['company'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'address' => $data['billing_address'] ?? null,
        ]) : [];

        // A donation that is not offered is zero, whatever arrived in the request: an event that
        // does not ask for one must not be able to be given one by a hand-written form.
        $donation = app(Donations::class)->amount($hold->event, $data['donation'] ?? 0);

        try {
            [$order, $intent] = $this->checkout->place(
                $site,
                $hold,
                ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null],
                $data['gateway'],
                $site->url('/order/'.$reference),
                $offer?->isAllowed() ? $offer : null,
                $billing,
                $data['addons'] ?? [],
                $donation,
            );
        } catch (ApiException $e) {
            if (in_array($e->errorCode(), ['addon_sold_out', 'addon_too_many', 'unknown_addon'], true)) {
                // The last programme went while this buyer was paying. Nothing has been charged
                // and their seats are still theirs, so they are sent back to choose again rather
                // than losing a booking over a five-euro extra.
                return redirect('/checkout')->with('seatmap_addon_error', $e->localisedMessage());
            }

            if ('discount_used_up' !== $e->errorCode()) {
                throw $e;
            }

            // Somebody else took the last use of the code between this buyer reading the total and
            // pressing pay. Nothing has been charged: they are sent back to a checkout that no
            // longer claims a discount, with their seats still held.
            $request->session()->forget('seatmap_discount');

            return redirect('/checkout')->with('seatmap_discount_error', 'used_up');
        }

        $request->session()->forget('seatmap_discount');

        // Written once the allocations exist: an answer about a seat, with no seat to point at, is
        // an answer nobody can find again.
        $questions->store($hold->event, $order, $answers, $this->placeAllocations($hold, $order));

        $request->session()->forget('seatmap_hold');
        $request->session()->put('seatmap_order', $order->external_order_id);

        /*
         * A ticket's QR token exists in plaintext exactly once, on the models that just issued it:
         * the database keeps only a hash, and nothing can recover the code afterwards.
         *
         * So it is kept in this buyer's own session rather than flashed to the next request. It has
         * to be: the confirmation page is not the only thing that needs it — the printable sheet
         * behind "Download tickets" is a second request, and a ticket without its code is not a
         * ticket. Nothing is given away by this that was not already given away: the same codes go
         * out by email a moment later, and this session is what guards the confirmation page too.
         */
        $request->session()->put('seatmap_tokens', $this->issuedTokens($order));

        $this->mail->send($site, $order);

        if ($intent->redirectUrl) {
            return redirect()->away($intent->redirectUrl);
        }

        return redirect('/order/'.$order->external_order_id);
    }

    /**
     * Where a redirect gateway sends the buyer back to.
     *
     * Nothing in this request is believed. The gateway is asked whether the money moved, over its
     * own API, with the reference we wrote down when the payment began — because the thing that
     * arrives here is a URL the buyer's browser followed, and a URL is something anybody can type.
     */
    public function paymentReturn(Request $request, string $gateway, string $reference)
    {
        $site = $request->attributes->get('site');

        $order = ExternalOrder::where('external_order_id', $reference)
            ->where('tenant_id', $site->tenant_id)
            ->first();

        if (! $order) {
            throw new NotFoundHttpException('No such order.');
        }

        // A gateway the site does not offer is not a gateway. Otherwise the return path would be
        // a way to ask any installed module to settle anybody's order.
        $allowed = array_map(fn ($entry) => $entry->key(), $this->gateways->enabledFor($site));

        if (! in_array($gateway, $allowed, true)) {
            throw new NotFoundHttpException('No such payment method.');
        }

        $order = $this->checkout->settle($order, $gateway, $request->all());

        // The buyer is put back where they would have been if the payment had settled inline. The
        // confirmation page is session-guarded, and this is that session.
        $request->session()->put('seatmap_order', $order->external_order_id);

        if ('confirmed' === $order->status) {
            $request->session()->put('seatmap_tokens', $this->issuedTokens($order));
            $this->mail->send($site, $order);
        }

        return redirect('/order/'.$order->external_order_id);
    }

    /**
     * The confirmation page, and the only place a buyer sees their ticket tokens.
     *
     * Reachable only from the session that placed the order: an order id in a URL is a guessable
     * thing, and a ticket token is a bearer credential for getting into a building.
     */
    /**
     * The tickets as a file the buyer keeps.
     *
     * A download rather than a page to print: a ticket has to survive being forwarded, printed at
     * work, and opened on a phone that has never seen this website. How it is built — and why that
     * takes a text engine rather than a template — is in App\Support\Pdf\TicketPdf.
     */
    /**
     * Straight into the phone, while the codes still exist.
     *
     * The plaintext codes live for one request — at issue time, in this session, and nowhere else
     * — so this is the moment a pass can be made without reissuing anything. Which is exactly why
     * it is offered on the confirmation page and not only in an account somebody logs into later.
     */
    public function wallet(Request $request, string $reference, string $platform)
    {
        $site = $request->attributes->get('site');
        $order = $this->ownOrder($request, $reference);
        $tokens = (array) $request->session()->get('seatmap_tokens', []);
        $wallets = app(Wallets::class);

        if ('google' === $platform) {
            return redirect()->away($wallets->google($site, $order, $tokens));
        }

        $pass = $wallets->apple($site, $order, $tokens);

        return response($pass['body'], 200, [
            'Content-Type' => $pass['type'],
            'Content-Disposition' => 'attachment; filename="'.$pass['filename'].'"',
            // The codes in here open a door. Nothing between us and the buyer keeps a copy.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function tickets(Request $request, string $reference)
    {
        $site = $request->attributes->get('site');
        $order = $this->ownOrder($request, $reference);
        $tokens = (array) $request->session()->get('seatmap_tokens', []);

        $pdf = app(TicketPdf::class)->render($site, $order, $tokens);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="tickets-'.$reference.'.pdf"',
            // The codes in here open a door. Nothing between us and the buyer should keep a copy.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The invoice for this booking, made on the first ask.
     *
     * Issued lazily rather than with every order: a number handed to a booking that was never paid
     * for is a gap in a sequence somebody later has to explain to an auditor.
     */
    public function invoice(Request $request, string $reference)
    {
        $site = $request->attributes->get('site');
        $order = $this->ownOrder($request, $reference);
        $issuer = app(InvoiceIssuer::class);

        if (! $issuer->isEligible($site, $order)) {
            throw new NotFoundHttpException('No invoice for this order.');
        }

        $invoice = $issuer->issue($site, $order);

        return response(app(InvoicePdf::class)->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.InvoiceIssuer::filename($invoice).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The order this browser bought, or nothing.
     *
     * A booking reference is short and printed on the confirmation page, so it is not a secret.
     * What makes this page the buyer's own is the session that made the purchase — the same check
     * the confirmation itself uses, because the two show the same thing.
     */
    private function ownOrder(Request $request, string $reference): ExternalOrder
    {
        if ($request->session()->get('seatmap_order') !== $reference) {
            throw new NotFoundHttpException('No such order.');
        }

        $order = ExternalOrder::with(['allocations.ticket', 'event.venue', 'addonLines'])
            ->where('external_order_id', $reference)
            ->first();

        if (! $order) {
            throw new NotFoundHttpException('No such order.');
        }

        return $order;
    }

    public function confirmation(Request $request, string $reference)
    {
        $site = $request->attributes->get('site');
        $order = $this->ownOrder($request, $reference);
        $tokens = (array) $request->session()->get('seatmap_tokens', []);

        return $this->view($site, 'site.order', [
            'title' => 'Your tickets · '.$site->name,
            'order' => $order,
            'tokens' => $tokens,
            'invoice' => app(InvoiceIssuer::class)->isEligible($site, $order),
            // Offered only where pressing it will actually work: "enabled" is a switch somebody
            // flicked, and a button that hands back an error is worse than no offer at all.
            'wallets' => $tokens ? app(Wallets::class)->offered() : ['apple' => false, 'google' => false],
            'qr' => fn (string $token) => $this->qr->dataUri($token, 200),
        ]);
    }

    /** @return array<string, string> allocation id => plaintext token */
    private function issuedTokens(ExternalOrder $order): array
    {
        $tokens = [];

        foreach ($order->allocations as $allocation) {
            if ($allocation->ticket?->plainToken) {
                $tokens[$allocation->id] = $allocation->ticket->plainToken;
            }
        }

        return $tokens;
    }

    private function heldSeats(Request $request): ?Hold
    {
        $token = $request->session()->get('seatmap_hold');

        if (! $token) {
            return null;
        }

        $hold = Hold::with('event')->where('token', $token)->first();

        return $hold && $hold->isActive() ? $hold : null;
    }

    /** The code this session is carrying, priced against this hold. Null when there is none. */
    private function offerFor(Request $request, Hold $hold): ?DiscountOffer
    {
        $typed = (string) $request->session()->get('seatmap_discount', '');

        return '' === $typed ? null : $this->offer($hold, $typed);
    }

    private function offer(Hold $hold, string $typed): DiscountOffer
    {
        return $this->discounts->offer(
            $typed,
            $hold->event,
            (int) $hold->total_amount,
            (string) $hold->currency,
            $this->seatCount($hold->price_snapshot['decoded'] ?? []),
        );
    }

    /** Why the last attempt was refused, said once. */
    private function discountError(Request $request): ?string
    {
        $reason = $request->session()->get('seatmap_discount_error');

        return $reason ? __('site.discount.refused.'.$reason) : null;
    }

    /**
     * How many places this basket is for.
     *
     * A standing area is a quantity, not a row, so counting hold items would say "two or more
     * people" is one — and "at least four tickets" is the most common thing a code asks for.
     */
    private function seatCount(array $snapshot): int
    {
        $count = count($snapshot['seats'] ?? []);

        foreach ($snapshot['areas'] ?? [] as $area) {
            $count += max(1, (int) ($area['quantity'] ?? 1));
        }

        return $count;
    }

    private function lines(array $snapshot): array
    {
        $lines = [];

        foreach ($snapshot['seats'] ?? [] as $seat) {
            $lines[] = [
                'label' => trim(implode(' · ', array_filter([
                    $seat['section'] ?? null, $seat['row'] ?? null, $seat['label'] ?? null,
                ]))),
                // Who it is for, on its own line under the seat. The name comes from the snapshot
                // rather than from the types table: it is what the buyer was shown when they chose,
                // and a type renamed since then must not change this receipt.
                'note' => $seat['ticket_type'] ?? null,
                'amount' => (int) ($seat['amount'] ?? 0),
            ];
        }

        foreach ($snapshot['areas'] ?? [] as $area) {
            $lines[] = [
                // The count in the reader's digits and the fallback in the reader's language: this
                // line sits directly under a price that is already shaped, and "2 × Floor" beside
                // "€۴۸٬۰۰" is two writing systems in one summary.
                'label' => Money::number($area['quantity'] ?? 1).' × '
                    .($area['label'] ?? __('site.standing')),
                'note' => $area['ticket_type'] ?? null,
                'amount' => (int) ($area['amount'] ?? 0),
            ];
        }

        return $lines;
    }

    /** Must match StorefrontCheckout's own derivation: the two name the same order. */
    private function reference(Hold $hold): string
    {
        return StorefrontCheckout::referenceFor($hold);
    }

    /**
     * The currency is the event's; the way it is written is the reader's (ADR-0005 §5).
     *
     * This replaced a symbol table and a divide-by-100. Both were wrong for the currencies that
     * matter most here: the rial has no minor unit at all, so dividing by a hundred understated
     * every Iranian price by two orders of magnitude.
     */
    private function money(int $minor, ?string $currency): string
    {
        return Money::format($minor, $currency ?: 'EUR');
    }

}
