<?php

namespace App\Http\Controllers\Site;

use App\Domain\Discounts\DiscountOffer;
use App\Domain\Discounts\Discounts;
use App\Domain\Invoicing\InvoiceIssuer;
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
            'subtotal' => (int) $hold->total_amount,
            'discount' => $offer ? [
                'code' => $offer->code->code,
                'amount' => $off,
            ] : null,
            'discountError' => $error,
            'extras' => $totals->extraLines(),
            'total' => $totals->total,
            'currency' => $hold->currency,
            'expires_at' => $hold->expires_at,
            'gateways' => $this->gateways->enabledFor($site),
            // Only asked for where an invoice can actually be issued. A company address field on a
            // site that cannot produce the document is a question with no purpose.
            'invoices' => $site->offersInvoices(),
        ]);
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

        $billing = ($site->offersInvoices() && $request->boolean('invoice')) ? array_filter([
            'company' => $data['company'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'address' => $data['billing_address'] ?? null,
        ]) : [];

        try {
            [$order, $intent] = $this->checkout->place(
                $site,
                $hold,
                ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null],
                $data['gateway'],
                $site->url('/order/'.$reference),
                $offer?->isAllowed() ? $offer : null,
                $billing,
            );
        } catch (ApiException $e) {
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

        $order = ExternalOrder::with(['allocations.ticket', 'event.venue'])
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
