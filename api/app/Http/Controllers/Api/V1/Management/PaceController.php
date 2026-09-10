<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Insights\SalesPace;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\Access\Gate;
use Illuminate\Http\Request;

/**
 * How one night is selling, and what happens to the people who look at it.
 *
 * Behind `events.view`, because a rate and a funnel are the programme's business rather than the
 * finance office's. The takings inside the same answer are behind `reports.orders.view` and are
 * left out entirely for somebody who does not hold it — the same separation EventStats makes, and
 * for the same reason: a screen that returned both would hand the money to whoever could see the
 * head count.
 */
class PaceController extends Controller
{
    public function __construct(private readonly SalesPace $pace) {}

    public function show(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        $data = $request->validate([
            // A fortnight, a month, a season. Bounded in the service as well, because a window of
            // thirty thousand days is a table scan somebody typed by accident.
            'days' => ['sometimes', 'integer', 'min:1', 'max:180'],
        ]);

        return response()->json($this->pace->forEvent(
            $event,
            (int) ($data['days'] ?? 30),
            app(Gate::class)->allows($request, 'reports.orders.view'),
        ));
    }
}
