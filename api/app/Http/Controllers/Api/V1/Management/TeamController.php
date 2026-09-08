<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\TenantInvitation;
use App\Models\TenantRole;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Access\Permissions;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Who is in this account, what they may do, and how somebody new gets in.
 *
 * Three rules hold this together, and each of them exists because of a way an account can end up
 * unusable or unsafe:
 *
 *   an account always keeps at least one owner, or nobody can grant anything ever again;
 *   nobody changes their own role, or every permission check is advisory;
 *   removing a member suspends them rather than deleting them, so the audit log keeps its names.
 */
class TeamController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    /* --------------------------------------------------------------------------- members */

    public function index(Request $request)
    {
        $this->authorize($request, 'team.view');

        $members = TenantUser::with('user')->get();

        return response()->json([
            'data' => $members->map(fn (TenantUser $member) => $this->present($member))->values(),
            'invitations' => TenantInvitation::with('invitedBy')
                ->whereNull('accepted_at')
                ->get()
                ->map(fn (TenantInvitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'invited_by' => $invitation->invitedBy?->name,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'expired' => ! $invitation->isPending(),
                ])->values(),
            'roles' => $this->roleList(),
        ]);
    }

    public function updateMember(Request $request, TenantUser $member)
    {
        $this->authorize($request, 'team.manage');

        $data = $request->validate([
            'role' => ['sometimes', 'string', 'max:60'],
            'suspended' => ['sometimes', 'boolean'],
        ]);

        // Nobody edits their own membership. Without this, the whole permission system is advice:
        // anyone with `team.manage` could give themselves everything, and the separation between a
        // box office and an owner would be one request wide.
        if ($member->user_id === $request->user()->id) {
            throw ApiException::denied('cannot_change_own_role', 'You cannot change your own role.');
        }

        if (isset($data['role'])) {
            $this->assertRoleExists($data['role']);
            $this->assertNotLastOwner($member, $data['role']);
            $member->role = $data['role'];
        }

        if (array_key_exists('suspended', $data)) {
            if ($data['suspended']) {
                $this->assertNotLastOwner($member, null);
            }

            $member->suspended_at = $data['suspended'] ? now() : null;
            $member->suspended_reason = $data['suspended'] ? 'suspended_by_admin' : null;
        }

        $member->save();

        $this->audit->record('team.member_updated', $member, [
            'member' => $member->user?->email,
            'role' => $member->role,
            'suspended' => $member->isSuspended(),
        ]);

        return response()->json($this->present($member->fresh('user')));
    }

    /* ----------------------------------------------------------------------- invitations */

    public function invite(Request $request)
    {
        $this->authorize($request, 'team.manage');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', 'max:60'],
        ]);

        $this->assertRoleExists($data['role']);

        $email = mb_strtolower($data['email']);

        $existing = User::where('email', $email)->first();

        if ($existing && TenantUser::where('user_id', $existing->id)->exists()) {
            throw ApiException::conflict('already_a_member', 'That person is already part of this account.');
        }

        $token = TenantInvitation::newToken();

        $invitation = TenantInvitation::updateOrCreate(
            ['email' => $email],
            [
                'role' => $data['role'],
                'token_hash' => TenantInvitation::hashToken($token),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ],
        );

        $this->audit->record('team.invited', $invitation, ['email' => $email, 'role' => $data['role']]);

        return response()->json([
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            /*
             * The token exists in plaintext exactly once — here — so it can be put in an email or
             * handed over in person. It is stored hashed, because an invitation link is a way into
             * an account and a leaked database should not be a set of working ones.
             */
            'token' => $token,
        ], 201);
    }

    public function revokeInvitation(Request $request, TenantInvitation $invitation)
    {
        $this->authorize($request, 'team.manage');

        $email = $invitation->email;
        $invitation->delete();

        $this->audit->record('team.invitation_revoked', null, ['email' => $email]);

        return response()->json(['deleted' => true]);
    }

    /* ----------------------------------------------------------------------------- roles */

    public function roles(Request $request)
    {
        $this->authorize($request, 'team.view');

        return response()->json([
            'data' => $this->roleList(),
            // The panel groups the checkboxes; sending the catalogue means it does not carry a
            // second copy of what permissions exist.
            // The whole array is read once and indexed, rather than translated key by key: a
            // permission name contains a dot, and `__('team.permissions.events.view')` sends
            // Laravel looking for a nested `events` array that does not exist.
            'permissions' => (function () {
                $labels = (array) trans('team.permissions');

                return array_map(
                    fn (string $permission) => [
                        'key' => $permission,
                        'group' => Permissions::ALL[$permission],
                        'label' => $labels[$permission] ?? $permission,
                    ],
                    Permissions::keys()
                );
            })(),
            'groups' => array_map(
                fn (string $group) => ['key' => $group, 'label' => __('team.groups.'.$group)],
                Permissions::groups()
            ),
        ]);
    }

    public function storeRole(Request $request)
    {
        $this->authorize($request, 'roles.manage');

        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:80'],
            'permissions' => ['present', 'array'],
        ]);

        if (in_array($data['key'], Permissions::RESERVED_ROLE_KEYS, true)) {
            throw ApiException::unprocessable('reserved_role', 'That name belongs to a built-in role.');
        }

        if (TenantRole::where('key', $data['key'])->exists()) {
            throw ApiException::conflict('role_exists', 'A role already uses that name.');
        }

        $role = TenantRole::create($data);

        $this->audit->record('role.created', $role, [
            'key' => $role->key,
            'permissions' => $role->permissions,
        ]);

        return response()->json($this->presentRole($role), 201);
    }

    public function updateRole(Request $request, TenantRole $role)
    {
        $this->authorize($request, 'roles.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'permissions' => ['sometimes', 'array'],
        ]);

        $before = $role->permissions;

        $role->update($data);

        $this->audit->record('role.updated', $role, [
            'key' => $role->key,
            'added' => array_values(array_diff($role->permissions, $before)),
            'removed' => array_values(array_diff($before, $role->permissions)),
        ]);

        return response()->json($this->presentRole($role->fresh()));
    }

    public function destroyRole(Request $request, TenantRole $role)
    {
        $this->authorize($request, 'roles.manage');

        // Deleting a role somebody holds would leave them with nothing, silently. Better to make
        // the person doing the deleting decide where those people go.
        if (TenantUser::where('role', $role->key)->exists()) {
            throw ApiException::conflict('role_in_use', 'Someone still has this role.');
        }

        $key = $role->key;
        $role->delete();

        $this->audit->record('role.deleted', null, ['key' => $key]);

        return response()->json(['deleted' => true]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function roleList(): array
    {
        $builtIn = array_map(
            fn (string $key) => [
                'key' => $key,
                'name' => __('team.roles.'.$key),
                'description' => __('team.roleDescriptions.'.$key),
                'built_in' => true,
                'permissions' => Permissions::forRole($key),
            ],
            Permissions::RESERVED_ROLE_KEYS
        );

        $custom = TenantRole::orderBy('name')->get()
            ->map(fn (TenantRole $role) => $this->presentRole($role))
            ->all();

        return array_merge($builtIn, $custom);
    }

    private function presentRole(TenantRole $role): array
    {
        return [
            'id' => $role->id,
            'key' => $role->key,
            'name' => $role->name,
            'description' => null,
            'built_in' => false,
            'permissions' => $role->permissions,
        ];
    }

    private function assertRoleExists(string $key): void
    {
        if (Permissions::isBuiltIn($key) || TenantRole::where('key', $key)->exists()) {
            return;
        }

        throw ApiException::unprocessable('unknown_role', 'That role does not exist.');
    }

    /**
     * An account must keep at least one owner who is not suspended.
     *
     * Otherwise an account can reach a state where nobody can grant anything — including the
     * ability to fix it — and the only way out is a support ticket and a database console.
     */
    private function assertNotLastOwner(TenantUser $member, ?string $newRole): void
    {
        if ('owner' !== $member->role) {
            return;
        }

        if ('owner' === $newRole) {
            return;
        }

        $others = TenantUser::where('role', 'owner')
            ->where('id', '!=', $member->id)
            ->whereNull('suspended_at')
            ->count();

        if (0 === $others) {
            throw ApiException::conflict('last_owner', 'An account must keep at least one owner.');
        }
    }

    private function present(TenantUser $member): array
    {
        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'name' => $member->user?->name,
            'email' => $member->user?->email,
            'role' => $member->role,
            'role_name' => Permissions::isBuiltIn($member->role)
                ? __('team.roles.'.$member->role)
                : (TenantRole::where('key', $member->role)->value('name') ?? $member->role),
            'suspended' => $member->isSuspended(),
            'last_seen_at' => $member->last_seen_at?->toIso8601String(),
        ];
    }
}
