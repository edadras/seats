<?php

namespace App\Http\Controllers\Site;

use App\Domain\Waitlist\WaitingList;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\RendersSitePages;
use App\Models\Event;
use App\Models\WaitingListEntry;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Joining the queue for a sold-out night, and leaving it.
 *
 * Both ends of the same promise. Every message this platform sends to somebody on a list carries
 * the link that takes them off it, and that link works without signing in — a person who wants to
 * stop hearing from you should not have to make an account to say so.
 */
class WaitingListController extends Controller
{
    use RendersSitePages;

    public function __construct(private readonly WaitingList $list) {}

    public function join(Request $request, string $publicId)
    {
        $site = $request->attributes->get('site');

        $event = Event::where('public_id', $publicId)
            ->whereIn('status', ['published', 'closed'])
            ->first();

        if (! $event) {
            throw new NotFoundHttpException('No such event.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $this->list->join($event, $data + ['locale' => app()->getLocale()]);

        // Back to the event, with a line saying it worked. Whether they were already on the list
        // is deliberately not said: it would answer "is this address on your list" to anybody.
        return redirect('/events/'.$event->public_id)
            ->with('seatmap_message', __('site.waitlist.joined'));
    }

    /**
     * Leave, from the link in the email.
     *
     * A GET, because that is what a mail client will follow, and it is safe to repeat: leaving a
     * list you have already left changes nothing.
     */
    public function leave(Request $request, string $token)
    {
        $site = $request->attributes->get('site');

        $entry = WaitingListEntry::where('token', $token)->first();

        if (! $entry) {
            throw new NotFoundHttpException('No such waiting list entry.');
        }

        $this->list->leave($entry);

        return $this->view($site, 'site.waitlist-left', [
            'title' => __('site.waitlist.leftTitle').' · '.$site->name,
        ]);
    }
}
