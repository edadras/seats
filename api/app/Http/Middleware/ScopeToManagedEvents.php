<?php

namespace App\Http\Middleware;

use App\Domain\Programme\EventManagers;
use App\Exceptions\ApiException;
use App\Models\Event;
use Closure;
use Illuminate\Http\Request;

/**
 * A programme manager runs some nights and not others.
 *
 * Every permission this platform has answers "what may this person do". A programme manager needs
 * the other half of the sentence — "to which nights" — and the two multiply: holding
 * `tickets.release` and being given the Tuesday means you may void a Tuesday ticket, and says
 * nothing whatever about Wednesday.
 *
 * Here rather than in seventy controllers, because a scope applied at each call site is a scope
 * that gets forgotten at one of them, and the one that is forgotten is the one somebody finds. Any
 * route with a bound `{event}` is covered by this, whatever it does with it — including the routes
 * that do not exist yet.
 *
 * The refusal is "cannot be found", not "not allowed": which nights an organiser is running is not
 * a manager's business either, and a refusal that distinguishes the two is a way of asking.
 */
class ScopeToManagedEvents
{
    public function __construct(private readonly EventManagers $managers) {}

    public function handle(Request $request, Closure $next)
    {
        $event = $request->route('event');

        if ($event instanceof Event && ! $this->managers->mayReach($request->user(), $event->id)) {
            throw ApiException::notFound('That event could not be found.', 'unknown_event');
        }

        return $next($request);
    }
}
