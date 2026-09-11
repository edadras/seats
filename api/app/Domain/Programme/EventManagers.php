<?php

namespace App\Domain\Programme;

use App\Models\Event;
use App\Models\EventManager;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Audit\AuditLogger;

/**
 * Programme managers: an administrator for one concert rather than for the account.
 *
 * A promoter puts on four nights in somebody else's venue. They need everything the venue's own
 * administrator has *for those four nights* — sell at the window, open and close seats on the plan,
 * read the door, read the takings — and nothing at all for the other two hundred, including the
 * knowledge that they exist.
 *
 * That is a different shape from every other role here, and the difference is the whole point.
 * Permissions answer "what may this person do"; this answers "to which nights", and the two are
 * multiplied rather than added: holding `tickets.release` and being given the Tuesday means you may
 * void a Tuesday ticket, and says nothing whatever about Wednesday.
 *
 * The list is the authority. A manager with no grants reaches nothing, which is the safe end to
 * fail at: an empty list must never read as "no restriction".
 */
class EventManagers
{
    /** The role a person holds while they are one. */
    public const ROLE = 'programme_manager';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Whether this person is a programme manager at all.
     *
     * Asked of the membership rather than of the grants, so somebody appointed this morning and not
     * yet given a night is still a manager — and therefore still scoped to nothing.
     */
    public function isOne(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return TenantUser::where('user_id', $user->id)
            ->where('role', self::ROLE)
            ->exists();
    }

    /**
     * The nights this person runs, or null for somebody who is not a manager.
     *
     * Null and [] are deliberately different answers: null is "this question does not apply to
     * you", [] is "you run nothing". Collapsing the two is how a scoped role quietly becomes an
     * unscoped one.
     *
     * @return list<string>|null
     */
    public function eventIdsFor(?User $user): ?array
    {
        if (! $this->isOne($user)) {
            return null;
        }

        return EventManager::where('user_id', $user->id)->pluck('event_id')->all();
    }

    /** Whether this person may reach this night. Anybody who is not a manager may reach all of them. */
    public function mayReach(?User $user, string $eventId): bool
    {
        $ids = $this->eventIdsFor($user);

        return null === $ids || in_array($eventId, $ids, true);
    }

    /**
     * Narrow a query to the nights this person runs. A no-op for everybody else.
     *
     * Applied to the query rather than to the screen, because a filter that lives in a panel is a
     * filter an API call goes around.
     */
    public function narrow($query, ?User $user, string $column = 'event_id')
    {
        $ids = $this->eventIdsFor($user);

        return null === $ids ? $query : $query->whereIn($column, $ids);
    }

    /**
     * Refuse a screen that is about the account rather than about a night.
     *
     * The customer directory, the report builder, the settlement of a whole season: there is no
     * honest way to show a promoter a quarter of those, and showing them the whole thing would hand
     * over the organiser's business along with the four nights they were booked for. Refused with a
     * reason rather than narrowed to nothing, because an empty screen is a bug report and this is a
     * decision.
     */
    public function assertNotScoped(?User $user): void
    {
        if ($this->isOne($user)) {
            throw \App\Exceptions\ApiException::denied(
                'not_your_programme',
                'This screen belongs to the account rather than to a night you run.',
            );
        }
    }

    /** Every manager in this account, with the nights each of them runs. */
    public function all(): array
    {
        $members = TenantUser::with('user')->where('role', self::ROLE)->get();

        $grants = EventManager::with('event:id,name,starts_at,status')
            ->whereIn('user_id', $members->pluck('user_id'))
            ->get()
            ->groupBy('user_id');

        return $members->map(fn (TenantUser $member) => [
            'user_id' => $member->user_id,
            'name' => $member->user?->name,
            'email' => $member->user?->email,
            'status' => $member->status,
            'last_seen_at' => $member->last_seen_at?->toIso8601String(),
            'events' => ($grants[$member->user_id] ?? collect())
                ->map(fn (EventManager $grant) => [
                    'id' => $grant->event_id,
                    'name' => $grant->event?->name,
                    'starts_at' => $grant->event?->starts_at?->toIso8601String(),
                    'status' => $grant->event?->status,
                ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Replace the whole list of nights this manager runs.
     *
     * Replaced rather than added to, because the screen shows a set of tick boxes and what comes
     * back is what the organiser meant — a grant that survived being unticked would be a grant
     * nobody could take away.
     *
     * @param  list<string>  $eventIds
     */
    public function grant(User $manager, array $eventIds, ?User $by = null): void
    {
        $tenantId = app(\App\Support\Tenancy\TenantContext::class)->idOrFail();

        $known = Event::whereIn('id', $eventIds)->pluck('id')->all();

        EventManager::where('user_id', $manager->id)->whereNotIn('event_id', $known)->delete();

        foreach ($known as $eventId) {
            EventManager::firstOrCreate(
                ['user_id' => $manager->id, 'event_id' => $eventId],
                ['tenant_id' => $tenantId, 'granted_by' => $by?->id],
            );
        }

        $this->audit->record('programme_manager.events_granted', $manager, [
            'name' => $manager->name,
            'events' => count($known),
        ]);
    }
}
