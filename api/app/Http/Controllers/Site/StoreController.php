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
        ]);

        $event = $this->event($data['event_public_id']);

        $hold = $this->holds->create(
            $event,
            $data['seat_ids'] ?? [],
            $this->sessionId($request),
            null,
            $request->ip(),
            $data['areas'] ?? [],
            $data['seat_types'] ?? [],
            $data['area_types'] ?? [],
        );

        // The token goes in the session, not to the browser as an identifier it could swap: the
        // checkout reads the buyer's hold from here and prices it from the server's own snapshot.
        $request->session()->put('seatmap_hold', $hold->token);

        return response()->json(
            (new HoldResource($hold->load('event')))->toArray($request) + ['cart_url' => '/checkout'],
            201
        );
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
