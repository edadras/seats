<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who changed what, when, and from where.
 *
 * The log has always been written; this is where it becomes readable. That matters more than it
 * sounds: a log nobody can read is a log nobody checks, and a log nobody checks is a log that has
 * silently stopped being written for six months.
 *
 * Read-only, by construction — there is no endpoint that edits or deletes a row here, and there
 * will not be. An audit trail an administrator can edit is a diary, not a record.
 */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize($request, 'audit.view');

        $data = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:80'],
            'actor_id' => ['sometimes', 'nullable', 'string', 'max:80'],
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'subject_id' => ['sometimes', 'nullable', 'string', 'max:80'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $logs = AuditLog::visible()
            ->when($data['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->when($data['actor_id'] ?? null, fn ($q, $actor) => $q->where('actor_id', $actor))
            ->when($data['subject_type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
            ->when($data['subject_id'] ?? null, fn ($q, $id) => $q->where('subject_id', $id))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to))
            // Newest first, and the id breaks ties: these are UUIDv7, so two rows written in the
            // same second still come back in the order they happened. Without it a page of a busy
            // second is in whatever order the planner felt like.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($data['per_page'] ?? 50), 200));

        // Names for the ids, resolved in one query rather than one per row.
        $actors = User::whereIn('id', $logs->pluck('actor_id')->filter()->unique())
            ->pluck('name', 'id');

        return $this->paginated($logs, fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'actor' => [
                'type' => $log->actor_type,
                'id' => $log->actor_id,
                // An API key or a device has no name in `users`; the type says which it was.
                'name' => $actors[$log->actor_id] ?? null,
            ],
            'subject' => [
                'type' => $log->subject_type,
                'id' => $log->subject_id,
                'label' => $log->subject_label,
            ],
            'changes' => $log->changes,
            'context' => $log->context,
            'ip' => $log->ip,
            'request_id' => $log->request_id,
            'created_at' => $log->created_at?->toIso8601String(),
        ]);
    }

    /** The distinct actions and actors present, so the panel's filters offer real values. */
    public function facets(Request $request)
    {
        $this->authorize($request, 'audit.view');

        $actions = AuditLog::visible()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $actorIds = AuditLog::visible()
            ->where('actor_type', 'user')
            ->whereNotNull('actor_id')
            ->distinct()
            ->pluck('actor_id');

        return response()->json([
            'actions' => $actions->values(),
            'actors' => User::whereIn('id', $actorIds)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])->values(),
        ]);
    }
}
