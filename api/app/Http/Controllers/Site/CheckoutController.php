<?php

namespace App\Http\Controllers\Site;

use App\Domain\Sites\Payments\GatewayRegistry;
use App\Domain\Sites\StorefrontCheckout;
use App\Domain\Sites\Themes;
use App\Domain\Sites\TicketMailer;
use App\Http\Controllers\Controller;
use App\Models\ExternalOrder;
use App\Models\Hold;
use App\Models\Site;
use App\Support\Locale\Money;
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
    public function __construct(
        private readonly StorefrontCheckout $checkout,
        private readonly GatewayRegistry $gateways,
        private readonly TicketMailer $mail,
        private readonly QrRenderer $qr,
    ) {}

    public function show(Request $request)
    {
        $site = $request->attributes->get('site');
        $hold = $this->heldSeats($request);

        if (! $hold) {
            return redirect('/')->with('seatmap_message', 'Your seats are no longer held. Please choose again.');
        }

        $snapshot = $hold->price_snapshot['decoded'] ?? [];

        return $this->view($site, 'site.checkout', [
            'title' => 'Checkout · '.$site->name,
            'hold' => $hold,
            'lines' => $this->lines($snapshot),
            'total' => $hold->total_amount,
            'currency' => $hold->currency,
            'expires_at' => $hold->expires_at,
            'gateways' => $this->gateways->enabledFor($site),
        ]);
    }

    public function place(Request $request)
    {
        $site = $request->attributes->get('site');
        $hold = $this->heldSeats($request);

        if (! $hold) {
            return redirect('/')->with('seatmap_message', 'Your seats are no longer held. Please choose again.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'gateway' => ['required', 'string', 'max:40'],
        ]);

        $allowed = array_map(fn ($g) => $g->key(), $this->gateways->enabledFor($site));

        if (! in_array($data['gateway'], $allowed, true)) {
            return back()->withInput()->withErrors(['gateway' => 'Choose a way to pay.']);
        }

        // The gateway may need somewhere to send the buyer back to, and it needs it before the
        // order exists — so the reference is derived from the hold, the same way the order id is.
        $reference = $this->reference($hold);

        [$order, $intent] = $this->checkout->place(
            $site,
            $hold,
            ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null],
            $data['gateway'],
            $site->url('/order/'.$reference),
        );

        $request->session()->forget('seatmap_hold');
        $request->session()->put('seatmap_order', $order->external_order_id);

        // A ticket's QR token exists in plaintext exactly once, on the models that just issued it.
        // Flash it to the next request so the buyer can see their own codes; nothing writes it
        // down, and a reload shows the page without them rather than re-minting anything.
        $request->session()->flash('seatmap_tokens', $this->issuedTokens($order));

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
            $request->session()->flash('seatmap_tokens', $this->issuedTokens($order));
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
    public function confirmation(Request $request, string $reference)
    {
        $site = $request->attributes->get('site');

        if ($request->session()->get('seatmap_order') !== $reference) {
            throw new NotFoundHttpException('No such order.');
        }

        $order = ExternalOrder::with(['allocations.ticket', 'event'])
            ->where('external_order_id', $reference)
            ->first();

        if (! $order) {
            throw new NotFoundHttpException('No such order.');
        }

        $tokens = (array) $request->session()->get('seatmap_tokens', []);

        return $this->view($site, 'site.order', [
            'title' => 'Your tickets · '.$site->name,
            'order' => $order,
            'tokens' => $tokens,
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

    private function lines(array $snapshot): array
    {
        $lines = [];

        foreach ($snapshot['seats'] ?? [] as $seat) {
            $lines[] = [
                'label' => trim(implode(' · ', array_filter([
                    $seat['section'] ?? null, $seat['row'] ?? null, $seat['label'] ?? null,
                ]))),
                'amount' => (int) ($seat['amount'] ?? 0),
            ];
        }

        foreach ($snapshot['areas'] ?? [] as $area) {
            $lines[] = [
                'label' => ($area['quantity'] ?? 1).' × '.($area['label'] ?? 'Standing'),
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

    private function view(Site $site, string $template, array $data)
    {
        $currency = $data['currency'] ?? $site->currency;

        return response()->view($template, $data + [
            'money' => fn (int $minor) => $this->money($minor, $currency),
            'site' => $site,
            'brand' => Themes::forSite($site),
            'description' => null,
            'canonical' => null,
            'headerMenu' => $site->menuFor('header'),
            'footerMenu' => $site->menuFor('footer'),
        ]);
    }
}
