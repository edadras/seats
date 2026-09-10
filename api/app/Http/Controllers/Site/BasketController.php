<?php

namespace App\Http\Controllers\Site;

use App\Domain\Baskets\Baskets;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BasketRecovery;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The link at the bottom of "you left something".
 *
 * Both routes are GETs, because both are things a mail client follows and neither can do harm by
 * being followed twice: one tries to put the same seats back in a basket, the other says no thank
 * you. Neither spends money, and neither is reachable without the token — which is long and random
 * precisely because following it puts seats in somebody's basket.
 *
 * The one thing this page will not do is quietly substitute. If the seats have gone — and an hour
 * after a hold expired they usually have — the buyer is told so and sent to the picker, rather than
 * being seated somewhere else and left to notice at the door.
 */
class BasketController extends Controller
{
    public function __construct(private readonly Baskets $baskets) {}

    /** Take the same seats again, and go and pay for them. */
    public function resume(Request $request, string $token)
    {
        $recovery = $this->recoveryOrFail($request, $token);

        try {
            $hold = $this->baskets->resume(
                $recovery,
                (string) $request->session()->getId(),
                $request->ip(),
            );
        } catch (ApiException $e) {
            // Every refusal here means something different to the person who followed the link, so
            // each is said in its own words and each sends them somewhere they can act.
            $event = $recovery->event;

            return redirect($event && 'basket_seats_gone' === $e->errorCode()
                ? '/events/'.$event->public_id
                : '/')->with('seatmap_message', $e->localisedMessage());
        }

        // A new session id: whoever followed this link is starting a purchase, and inheriting
        // whatever this browser was doing on this site before is not it.
        $request->session()->regenerate();
        $request->session()->put('seatmap_hold', $hold->token);
        // Remembered so the checkout can mark the recovery as having worked. The id, not the
        // token: what is in a session is this browser's business and the token is in an email.
        $request->session()->put('seatmap_recovery', $recovery->id);

        return redirect('/checkout')->with('seatmap_message', __('site.basket.backAgain'));
    }

    /** No thank you. Never written to about this basket again. */
    public function decline(Request $request, string $token)
    {
        $recovery = $this->recoveryOrFail($request, $token);

        $this->baskets->decline($recovery);

        return redirect('/')->with('seatmap_message', __('site.basket.declined'));
    }

    private function recoveryOrFail(Request $request, string $token): BasketRecovery
    {
        $site = $request->attributes->get('site');

        $recovery = BasketRecovery::with(['order.hold', 'event'])
            ->where('token', $token)
            ->first();

        // Scoped to this site as well as to the token: a link belongs to the shop that sent it,
        // and a token that resolved on somebody else's domain would be a basket crossing a border
        // the rest of this application does not let anything cross.
        if (! $recovery || ($recovery->site_id && $recovery->site_id !== $site->id)) {
            throw new NotFoundHttpException('No such basket.');
        }

        return $recovery;
    }
}
