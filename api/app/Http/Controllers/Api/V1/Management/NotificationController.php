<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Notifications\NotificationKinds;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Support\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What the platform has told this account, and what this person has already seen.
 *
 * Each kind is governed by the permission that governs the thing it is about — a refund notice by
 * `orders.view`, a failed message by `account.manage` — and that is applied here, on the way out,
 * rather than when the row was written. Roles change: somebody promoted on Tuesday should see
 * Monday's news, and somebody who loses the box office should stop seeing refunds.
 *
 * The sentence is composed here too, in the reader's own language, from a key this platform owns.
 * A notification stored as English prose is a notification a Persian colleague reads in English.
 */
class NotificationController extends Controller
{
    private const PAGE = 30;

    public function __construct(private readonly Gate $gate) {}

    public function index(Request $request)
    {
        $visible = $this->visibleKinds($request);

        if ([] === $visible) {
            return response()->json(['data' => [], 'unread' => 0]);
        }

        $userId = $request->user()->id;

        $notifications = Notification::query()
            ->whereIn('kind', $visible)
            ->with(['reads' => fn ($query) => $query->where('user_id', $userId)])
            ->orderByDesc('created_at')
            ->limit(self::PAGE)
            ->get();

        return response()->json([
            'data' => $notifications->map(fn (Notification $notification) => [
                'id' => $notification->id,
                'kind' => $notification->kind,
                'level' => $notification->level,
                'title' => __(NotificationKinds::key($notification->kind, 'title'), $notification->params ?? []),
                'body' => __(NotificationKinds::key($notification->kind, 'body'), $notification->params ?? []),
                'subject' => $notification->subject_label,
                'created_at' => $notification->created_at?->toIso8601String(),
                'read' => $notification->reads->isNotEmpty(),
            ])->values(),
            'unread' => $this->unread($request, $visible, $userId),
        ]);
    }

    /**
     * Mark them seen.
     *
     * Everything currently visible to this person, not "everything": marking as read what somebody
     * was never shown is how a notice disappears before anybody reads it.
     */
    public function read(Request $request)
    {
        $visible = $this->visibleKinds($request);
        $userId = $request->user()->id;

        $ids = Notification::query()
            ->whereIn('kind', $visible)
            ->orderByDesc('created_at')
            ->limit(200)
            ->pluck('id');

        $now = now();

        // One upsert rather than a model per row: the read mark's key is the pair of ids, and an
        // Eloquent model with a composite key would be updating by an `id` column that does not
        // exist on this table.
        if ($ids->isNotEmpty()) {
            DB::table('notification_reads')->upsert(
                $ids->map(fn (string $id) => [
                    'notification_id' => $id,
                    'user_id' => $userId,
                    'read_at' => $now,
                ])->all(),
                ['notification_id', 'user_id'],
                ['read_at'],
            );
        }

        return response()->json(['unread' => 0]);
    }

    /* --------------------------------------------------------------------------- helpers */

    /** @return list<string> */
    private function visibleKinds(Request $request): array
    {
        return array_values(array_filter(
            array_keys(NotificationKinds::KINDS),
            fn (string $kind) => $this->gate->allows($request, (string) NotificationKinds::permission($kind))
        ));
    }

    private function unread(Request $request, array $visible, string $userId): int
    {
        return Notification::query()
            ->whereIn('kind', $visible)
            ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $userId))
            ->count();
    }
}
