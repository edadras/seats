<?php

namespace App\Http\Controllers\Site;

use App\Domain\Renewals\Renewals;
use App\Domain\Sites\StorefrontCheckout;
use App\Domain\Sites\Themes;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\RenewalOffer;
use App\Models\Site;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Your seats are yours again, until the fourteenth of June."
 *
 * Behind a signed link and no sign-in, for the same reason the unsubscribe page is: a subscriber is
 * somebody who bought tickets, not somebody with an account, and a renewal that asks for a password
 * they never made is a renewal that lapses. The signature is over the offer and the address it was
 * written to, so a link cannot be moved to somebody else's chairs.
 *
 * Taking them does not buy anything here. It makes the ordinary hold that any buyer makes, with
 * these seats in it, and hands them to the season checkout that already sells subscriptions —
 * where the price is worked out once, shown, and paid for. This page never prices anything.
 */
class RenewalController extends Controller
{
    public function __construct(private readonly Renewals $renewals) {}

    public function show(Request $request, string $offer, string $token)
    {
        [$site, $found] = $this->check($request, $offer, $token);

        return $this->render($site, $found, $token);
    }

    /**
     * Take them.
     *
     * The seats are held first and the subscriber is sent to pay. If the hold cannot be made — the
     * run has no nights on sale yet, say — nothing changes and they are told why on this page,
     * rather than being dropped on a checkout with an empty basket.
     */
    public function accept(Request $request, string $offer, string $token)
    {
        [$site, $found] = $this->check($request, $offer, $token);

        try {
            $taken = $this->renewals->accept(
                $found,
                (string) $request->session()->getId(),
                $request->ip(),
                app(StorefrontCheckout::class)->clientFor($site)->id,
            );
        } catch (ApiException $e) {
            return $this->render($site, $found->refresh(), $token, $e->localisedMessage());
        }

        /*
         * The ordinary subscriber's session, with the seats already chosen.
         *
         * `seatmap_hold` is the first night; the season controller spreads it across the rest when
         * the checkout is drawn. Nothing here reimplements what a season costs.
         */
        $request->session()->put('seatmap_hold', $taken['hold']->token);
        $request->session()->put('seatmap_season', $found->round->season_pass_id);
        $request->session()->put('seatmap_season_nights', $taken['nights']);
        $request->session()->forget('seatmap_season_holds');

        return redirect('/season/checkout');
    }

    /** No thank you. The chairs go back on general sale now rather than at the deadline. */
    public function decline(Request $request, string $offer, string $token)
    {
        [$site, $found] = $this->check($request, $offer, $token);

        try {
            $this->renewals->decline($found);
        } catch (ApiException $e) {
            return $this->render($site, $found->refresh(), $token, $e->localisedMessage());
        }

        return $this->render($site, $found->refresh(), $token, null, true);
    }

    /* --------------------------------------------------------------------------- internals */

    /** @return array{0: Site, 1: RenewalOffer} */
    private function check(Request $request, string $offer, string $token): array
    {
        $site = $request->attributes->get('site');

        if (! $site) {
            throw new NotFoundHttpException('No such page.');
        }

        $found = RenewalOffer::with(['round', 'seats.seat.row', 'seats.seat.section'])
            ->where('tenant_id', $site->tenant_id)
            ->find($offer);

        /*
         * A bad signature is a 404 rather than a 403, and so is an offer belonging to another
         * account. "Wrong token" would tell somebody guessing that the offer exists — and an offer
         * existing says that a named person subscribes to this theatre.
         */
        if (! $found || ! $this->renewals->tokenIsGood($found, $token)) {
            throw new NotFoundHttpException('No such page.');
        }

        return [$site, $found];
    }

    private function render(Site $site, RenewalOffer $offer, string $token, ?string $error = null, bool $declined = false)
    {
        $round = $offer->round;
        $nights = $round ? $this->renewals->nights($round) : collect();

        return response()->view('site.renewal', [
            'site' => $site,
            'brand' => Themes::forSite($site),
            'title' => __('site.renewal.title').' · '.$site->name,
            'description' => null,
            'canonical' => null,
            'image' => null,
            'jsonld' => null,
            'headerMenu' => $site->menuFor('header'),
            'footerMenu' => $site->menuFor('footer'),
            'offer' => $offer,
            'token' => $token,
            'round' => $round,
            'live' => (bool) $round?->isLive(),
            'seats' => $offer->seats->map(fn ($row) => trim(
                ($row->seat?->section?->name ?? '').' '.
                ($row->seat?->row?->name ?? '').' '.
                ($row->seat?->label ?? '')
            ))->values()->all(),
            'nights' => $nights->map(fn (Event $night) => [
                'name' => $night->nameFor(),
                'starts' => $night->starts_at,
                'timezone' => $night->timezone,
            ])->all(),
            'error' => $error,
            'declined' => $declined,
        ]);
    }
}
