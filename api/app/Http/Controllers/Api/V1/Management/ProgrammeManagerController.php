<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Programme\EventManagers;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Appointing somebody to run a concert.
 *
 * Two authorities, and both are required: `team.manage`, because this makes a member of the
 * account, and `events.manage`, because what it hands over is nights. An organiser who may add
 * people but not run the programme has no business deciding who runs the Tuesday, and the other way
 * round.
 */
class ProgrammeManagerController extends Controller
{
    public function __construct(
        private readonly EventManagers $managers,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'team.view');

        return response()->json(['data' => $this->managers->all()]);
    }

    /**
     * Make one, with a way in.
     *
     * The password exists in plaintext exactly once — on this response — the same bargain the
     * platform makes with an API secret and with an agency's sign-in. The organiser passes it on
     * and the manager changes it; there is no second copy anywhere.
     */
    public function store(Request $request)
    {
        $this->authorize($request, 'team.manage');
        $this->authorize($request, 'events.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'event_ids' => ['sometimes', 'array', 'max:200'],
            'event_ids.*' => ['uuid'],
        ]);

        $email = mb_strtolower($data['email']);

        if (User::where('email', $email)->exists()) {
            throw ApiException::conflict('already_a_member', 'That person is already part of this account.');
        }

        $password = Str::password(14, symbols: false);

        $user = User::create([
            'name' => $data['name'],
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // Verified as it is made: the organiser typed the address and the password goes to them to
        // pass on, so a bar promising a six-digit code would be promising one nobody sent.
        $user->forceFill(['email_verified_at' => now()])->save();

        TenantUser::create([
            'tenant_id' => app(\App\Support\Tenancy\TenantContext::class)->idOrFail(),
            'user_id' => $user->id,
            'role' => EventManagers::ROLE,
        ]);

        $this->managers->grant($user, $data['event_ids'] ?? [], $request->user());

        $this->audit->record('programme_manager.created', $user, [
            'name' => $data['name'],
            'email' => $email,
        ]);

        return response()->json([
            'email' => $email,
            'password' => $password,
            'data' => $this->managers->all(),
        ], 201);
    }

    /** Change which nights they run. The list replaces what was there. */
    public function grant(Request $request, User $manager)
    {
        $this->authorize($request, 'team.manage');
        $this->authorize($request, 'events.manage');

        $data = $request->validate([
            'event_ids' => ['present', 'array', 'max:200'],
            'event_ids.*' => ['uuid'],
        ]);

        $this->assertIsOne($manager);

        $this->managers->grant($manager, $data['event_ids'], $request->user());

        return response()->json(['data' => $this->managers->all()]);
    }

    /**
     * Somebody who is not a programme manager of this account.
     *
     * Not found rather than forbidden: a user id that belongs to another account, or to nobody,
     * must answer the same way as one that belongs to a member with a different job — otherwise
     * this endpoint is a way of asking who exists.
     */
    private function assertIsOne(User $manager): void
    {
        $member = TenantUser::where('user_id', $manager->id)
            ->where('role', EventManagers::ROLE)
            ->first();

        if (! $member) {
            throw ApiException::notFound('That programme manager could not be found.', 'unknown_manager');
        }
    }
}
