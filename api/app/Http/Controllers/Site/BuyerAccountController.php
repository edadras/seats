<?php

namespace App\Http\Controllers\Site;

use App\Domain\Orders\TicketIssuer;
use App\Domain\Sites\Auth\GoogleIdentity;
use App\Domain\Sites\Themes;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\RendersSitePages;
use App\Models\ExternalOrder;
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
            'orders' => $buyer ? $this->orders($site, $buyer['email']) : [],
            'canSignIn' => $this->available($site),
            'notice' => $request->query('signin'),
        ]);
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
    public function tickets(Request $request, string $reference)
    {
        $site = $request->attributes->get('site');
        $buyer = $this->signedIn($request);

        if (! $buyer) {
            throw new NotFoundHttpException('Not signed in.');
        }

        $order = $this->ownOrder($buyer['email'], $reference);
        $tokens = [];

        foreach ($order->allocations as $allocation) {
            $ticket = $this->tickets->reissue($allocation);

            if ($ticket?->plainToken) {
                $tokens[$allocation->id] = $ticket->plainToken;
            }
        }

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
                'placed_at' => $order->created_at,
                'total' => Money::format((int) $order->total_amount, (string) $order->currency),
                'event' => $order->event,
                'lines' => $order->allocations->map(fn ($allocation) => [
                    'seat' => trim(implode(' · ', array_filter([
                        $allocation->section_name, $allocation->row_name,
                        $allocation->seat_id ? $allocation->seat_label : null,
                    ]))),
                    'quantity' => $allocation->seat_id ? 1 : (int) ($allocation->quantity ?: 1),
                    'used' => (bool) $allocation->ticket?->used_at,
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
