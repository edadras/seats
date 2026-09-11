<?php

namespace App\Http\Controllers\Site;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Inventory\HoldService;
use App\Http\Controllers\Controller;
use App\Http\Resources\HoldResource;
use App\Models\Event;
use App\Models\Hold;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The three routes the seat picker talks to on a hosted site.
 *
 * They mirror the WordPress plugin's store routes exactly — same shapes, same names — because the
 * picker is one shared implementation and must not learn which kind of shop it is embedded in.
 *
 * The browser never reaches the seating API directly for anything that sets a price. It asks this
 * site, and this site asks the domain services in-process. That is the same rule ADR-0001 drew for
 * WooCommerce, and it holds here for the same reason: a price the browser could name is a price the
 * browser could argue with.
 */
class StoreController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly HoldService $holds,
    ) {}

    public function availability(Request $request, string $publicId)
    {
        $event = $this->event($publicId);

        return response()->json([
            'cursor' => (string) $event->availability_version,
            'full' => true,
            'seats' => $this->availability->forEvent($event),
            'areas' => $this->availability->capacityForEvent($event),
            'entry_slots' => app(\App\Domain\Events\EntrySlots::class)->forEvent($event, openOnly: true),
        ]);
    }

    public function hold(Request $request)
    {
        // The event travels in the body, not the path, because that is the shape the shared picker
        // already posts — it learned it from the WordPress plugin's store route.
        $data = $request->validate([
            'event_public_id' => ['required', 'string', 'max:60'],
            'seat_ids' => ['sometimes', 'array', 'max:'.config('seatmap.hold.max_seats')],
            'seat_ids.*' => ['uuid'],
            'areas' => ['sometimes', 'array', 'max:20'],
            'areas.*' => ['integer', 'min:1', 'max:'.config('seatmap.hold.max_seats')],
            // Who each ticket is for; see EmbedController::hold, which this mirrors on purpose.
            'seat_types' => ['sometimes', 'array', 'max:'.config('seatmap.hold.max_seats')],
            'seat_types.*' => ['uuid'],
            'area_types' => ['sometimes', 'array', 'max:20'],
            'area_types.*' => ['array', 'max:20'],
            'area_types.*.*' => ['integer', 'min:1', 'max:'.config('seatmap.hold.max_seats')],
            'entry_slot_id' => ['sometimes', 'nullable', 'uuid'],
            // A presale code, where this event is not yet open to everybody. Taken from the body
            // as well as the session, because the picker sends it the moment it is unlocked and
            // a session that lost it must not silently sell at the wrong time.
            'access_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            // "Four together, please", instead of naming the chairs. The server chooses and holds
            // in one movement, because a suggestion a buyer has to confirm is a suggestion somebody
            // else can take in between.
            'best_available' => ['sometimes', 'array'],
            'best_available.quantity' => ['required_with:best_available', 'integer', 'min:1',
                'max:'.config('seatmap.hold.max_seats')],
            'best_available.max_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'best_available.zone_key' => ['sometimes', 'nullable', 'string', 'max:60'],
            'best_available.section_key' => ['sometimes', 'nullable', 'string', 'max:60'],
            'best_available.prefer' => ['sometimes', 'nullable', 'in:best,cheapest'],
            'best_available.ticket_type_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $event = $this->event($data['event_public_id']);

        /*
         * Who is signed in on this site, where anybody is.
         *
         * Read from the session this server wrote rather than from anything the request carries:
         * it decides whether somebody's standing opens a presale, and an address the browser could
         * name would be a presale anybody could name their way into.
         */
        $signedIn = $request->session()->get('seatmap_buyer')['email'] ?? null;

        /*
         * The door, where this night has one.
         *
         * Checked here and not only on the page, because a page is a suggestion and this is the
         * moment inventory moves. A waiting room that only hid the picker would be a waiting room
         * anybody could walk past with a browser console open.
         */
        if (! app(\App\Http\Controllers\Site\QueueController::class)->isAdmitted($request, $event)) {
            throw \App\Exceptions\ApiException::conflict(
                'waiting_your_turn',
                'It is not your turn yet. Your place in the queue is being held.'
            );
        }

        $accessCode = $data['access_code'] ?? $request->session()->get('seatmap_access_code');

        /*
         * Which channel this basket belongs to.
         *
         * The site's own API client — the one its orders are already registered against. Passing it
         * here as well makes the *hold* attributable to the channel it came from, which is what a
         * quota has to count: a limit that only saw completed bookings would let a channel hold
         * four hundred places it had no allowance for and find out at the checkout.
         */
        $client = app(\App\Domain\Sites\StorefrontCheckout::class)
            ->clientFor($request->attributes->get('site'))->id;

        $hold = ($data['best_available'] ?? null)
            ? $this->holds->createBestAvailable(
                $event,
                (int) $data['best_available']['quantity'],
                $this->sessionId($request),
                $client,
                $request->ip(),
                $data['best_available'],
                ['all' => $data['best_available']['ticket_type_id'] ?? null],
                $data['entry_slot_id'] ?? null,
                $accessCode,
                $signedIn,
            )
            : $this->holds->create(
                $event,
                $data['seat_ids'] ?? [],
                $this->sessionId($request),
                $client,
                $request->ip(),
                $data['areas'] ?? [],
                $data['seat_types'] ?? [],
                $data['area_types'] ?? [],
                $data['entry_slot_id'] ?? null,
                $accessCode,
                $signedIn,
            );

        // The token goes in the session, not to the browser as an identifier it could swap: the
        // checkout reads the buyer's hold from here and prices it from the server's own snapshot.
        $request->session()->put('seatmap_hold', $hold->token);

        return response()->json(
            (new HoldResource($hold->load('event')))->toArray($request) + ['cart_url' => '/checkout'],
            201
        );
    }

    /**
     * "I have a code" — before any seats are chosen.
     *
     * Answering here rather than at the first hold is the difference between a buyer being told
     * the sale is not open to them and a buyer choosing four seats and then being told. Nothing is
     * reserved: two people may both be told yes and only one of them get in, which is true of
     * every sale and is why the real check happens under the lock at hold time.
     *
     * The accepted code goes in the session, so the picker does not have to carry it through every
     * request and a reload does not lock somebody out of a sale they were already in.
     */
    public function unlock(Request $request)
    {
        $data = $request->validate([
            'event_public_id' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:40'],
        ]);

        $event = $this->event($data['event_public_id']);
        $offer = app(\App\Domain\Access\AccessCodes::class)->offer($data['code'], $event);

        if (! $offer->ok) {
            return response()->json([
                'ok' => false,
                // The reason, not a bare no: "that code is not right" and "that presale opens on
                // Friday" send a person to two different next steps, and only one is the telephone.
                'reason' => $offer->reason,
                'message' => __('site.access.'.$offer->reason),
            ], 422);
        }

        $request->session()->put('seatmap_access_code', $offer->code->code);

        return response()->json([
            'ok' => true,
            'label' => $offer->code->label,
            'max_seats' => $offer->code->max_seats,
        ]);
    }

    public function release(Request $request)
    {
        $token = $request->session()->pull('seatmap_hold');

        if ($token) {
            $hold = Hold::where('token', $token)->first();

            if ($hold) {
                $this->holds->release($hold);
            }
        }

        return response()->json(['released' => true]);
    }

    /**
     * A stable per-browser id, so a buyer who reloads keeps their own holds and the per-session
     * cap on concurrent holds means something. It is not an identity and never authorises anything.
     */
    private function sessionId(Request $request): string
    {
        $id = $request->session()->get('seatmap_session_id');

        if (! $id) {
            $id = 'site-'.$request->session()->getId();
            $request->session()->put('seatmap_session_id', $id);
        }

        return $id;
    }

    private function event(string $publicId): Event
    {
        $event = Event::with(['priceZones', 'venue'])->where('public_id', $publicId)->first();

        if (! $event || 'published' !== $event->status) {
            throw new NotFoundHttpException('No such event.');
        }

        return $event;
    }
}
