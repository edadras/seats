<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Agents\SalesAgents;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentCreditEntry;
use App\Models\SalesAgent;
use App\Models\SalesAgentEvent;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The shops and bureaux that sell an organiser's tickets, and their accounts.
 *
 * Behind `agents.manage`, which the roles that hold the box office have and a seller does not: the
 * person who works the window is not thereby the person who decides an agency may owe eleven
 * thousand euros. The agent's own view of the same account is `/agent/summary`, which needs no
 * permission at all because it is about the caller.
 */
class SalesAgentController extends Controller
{
    public function __construct(
        private readonly SalesAgents $agents,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'agents.manage');

        return response()->json([
            'data' => SalesAgent::orderBy('name')->get()
                ->map(fn (SalesAgent $agent) => $this->present($agent, withMoney: true))
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, SalesAgent $agent)
    {
        $this->authorize($request, 'agents.manage');

        return response()->json($this->present($agent, withMoney: true) + [
            'events' => $this->agents->events($agent)->map(fn ($event) => [
                'id' => $event->id,
                'name' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'status' => $event->status,
            ])->values()->all(),
            'ledger' => $this->agents->ledger($agent),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'agents.manage');

        $agent = SalesAgent::create($this->validated($request, creating: true));

        $this->audit->record('agent.created', $agent, ['name' => $agent->name, 'code' => $agent->code]);

        return response()->json($this->present($agent), 201);
    }

    public function update(Request $request, SalesAgent $agent)
    {
        $this->authorize($request, 'agents.manage');

        $agent->fill($this->validated($request, creating: false));

        $this->audit->recordChange('agent.updated', $agent);

        $agent->save();

        return response()->json($this->present($agent->fresh(), withMoney: true));
    }

    /**
     * Switch an agent off, or delete one who never sold anything.
     *
     * An agent with sales against their name is deactivated rather than deleted: what they sold is
     * a fact about those bookings, and the money between the two parties is not settled by
     * forgetting whose it was.
     */
    public function destroy(Request $request, SalesAgent $agent)
    {
        $this->authorize($request, 'agents.manage');

        if ($agent->orders()->exists() || $agent->entries()->exists()) {
            $agent->forceFill(['active' => false])->save();

            $this->audit->record('agent.deactivated', $agent, ['name' => $agent->name]);

            return response()->json($this->present($agent->fresh(), withMoney: true) + ['deactivated' => true]);
        }

        $this->audit->record('agent.deleted', $agent, ['name' => $agent->name]);

        $agent->delete();

        return response()->json(['deleted' => true]);
    }

    /** Replace the list of what this agent may sell. */
    public function allow(Request $request, SalesAgent $agent)
    {
        $this->authorize($request, 'agents.manage');

        $data = $request->validate([
            'all_events' => ['sometimes', 'boolean'],
            'event_ids' => ['sometimes', 'array', 'max:500'],
            'event_ids.*' => ['uuid'],
        ]);

        $this->agents->allow(
            $agent,
            $data['event_ids'] ?? [],
            (bool) ($data['all_events'] ?? false),
        );

        return response()->json($this->show($request, $agent->fresh())->getData(true));
    }

    /** Money in from an agent, money out to them, or an adjustment somebody signed. */
    public function record(Request $request, SalesAgent $agent)
    {
        $this->authorize($request, 'agents.manage');

        $data = $request->validate([
            'kind' => ['required', Rule::in(AgentCreditEntry::KINDS)],
            'amount' => ['required', 'integer'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'method' => ['sometimes', 'nullable', Rule::in(\App\Domain\BoxOffice\Tills::METHODS)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:190'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $this->agents->record($agent, $data, $request->user());

        return response()->json($this->present($agent->fresh(), withMoney: true) + [
            'ledger' => $this->agents->ledger($agent),
        ], 201);
    }

    /**
     * Give this agent a way in.
     *
     * A reseller is not a colleague being invited onto the team; they are a shop being handed a
     * key, usually over the telephone while somebody writes it down. So the credentials are created
     * here and the password is shown exactly once — the same bargain the platform already makes
     * with an API secret, and for the same reason: it exists in plaintext for as long as it takes
     * to pass it on, and never again.
     *
     * Refused where the address already belongs to somebody, because two accounts sharing an email
     * is how a sign-in stops being evidence of who did something.
     */
    public function signIn(Request $request, SalesAgent $agent)
    {
        $this->authorize($request, 'agents.manage');
        $this->authorize($request, 'team.manage');

        $data = $request->validate([
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $email = mb_strtolower((string) ($data['email'] ?? $agent->contact_email ?? ''));

        if ('' === $email) {
            throw ApiException::unprocessable(
                'agent_needs_email',
                'An agent needs an email address before they can be given a sign-in.',
            );
        }

        if (\App\Models\User::where('email', $email)->exists()) {
            throw ApiException::conflict(
                'already_a_member',
                'That person is already part of this account.',
            );
        }

        $password = \Illuminate\Support\Str::password(14, symbols: false);

        $user = \App\Models\User::create([
            'name' => $data['name'] ?? $agent->contact_name ?? $agent->name,
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
        ]);

        /*
         * Verified as it is made, because the organiser is the one who typed the address and the
         * password goes to them to pass on. Left unverified, the panel would greet the agency with
         * a bar saying a six-digit code had been sent — and none had, because this account was
         * never signed up for. A promise nobody kept is worse than no bar.
         */
        $user->forceFill(['email_verified_at' => now()])->save();

        \App\Models\TenantUser::create([
            'tenant_id' => $agent->tenant_id,
            'user_id' => $user->id,
            'role' => 'agent',
        ]);

        $agent->forceFill(['user_id' => $user->id, 'contact_email' => $email])->save();

        $this->audit->record('agent.sign_in_created', $agent, [
            'name' => $agent->name,
            'email' => $email,
        ]);

        return response()->json([
            'email' => $email,
            // Once. The organiser passes it on and the agent changes it; there is no second copy
            // of it anywhere, here or in the database.
            'password' => $password,
            'agent' => $this->present($agent->fresh(), withMoney: true),
        ], 201);
    }

    /** What an agent sold in a period, what it earned them, and what is outstanding now. */
    public function statement(Request $request, SalesAgent $agent)
    {
        $this->authorize($request, 'agents.manage');

        $data = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        return response()->json($this->agents->statement(
            $agent,
            $data['from'] ?? null,
            $data['to'] ?? null,
        ));
    }

    /**
     * The agent's own view of their own account.
     *
     * No permission: it is about the caller, and a caller who is not an agent is told so rather
     * than refused — a box-office user opening the same screen should see "you are not an agent",
     * not a wall.
     */
    public function summary(Request $request)
    {
        $agent = $this->agents->forUser($request->user());

        if (! $agent) {
            return response()->json(['agent' => null]);
        }

        return response()->json([
            'agent' => $this->present($agent, withMoney: true),
            'events' => $this->agents->events($agent)->map(fn ($event) => [
                'id' => $event->id,
                'name' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'status' => $event->status,
                'currency' => $event->currency,
            ])->values()->all(),
            'ledger' => $this->agents->ledger($agent, 20),
        ]);
    }

    /* -------------------------------------------------------------------------- internals */

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $agent = $request->route('agent');

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'code' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('sales_agents', 'code')
                    ->where('tenant_id', app(\App\Support\Tenancy\TenantContext::class)->idOrFail())
                    ->ignore($agent instanceof SalesAgent ? $agent->id : null),
            ],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Who signs in as this agent. Checked as a member of this organiser, because an agent
            // who is not on the team cannot reach the counter to sell anything.
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'commission_rate' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'credit_limit' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(SalesAgent $agent, bool $withMoney = false): array
    {
        $row = [
            'id' => $agent->id,
            'name' => $agent->name,
            'code' => $agent->code,
            'contact_name' => $agent->contact_name,
            'contact_email' => $agent->contact_email,
            'contact_phone' => $agent->contact_phone,
            'user_id' => $agent->user_id,
            'commission_rate' => (int) $agent->commission_rate,
            'commission_percent' => $agent->commissionPercent(),
            'credit_limit' => (int) $agent->credit_limit,
            'all_events' => (bool) $agent->all_events,
            'active' => (bool) $agent->active,
            'note' => $agent->note,
            'event_count' => $agent->all_events
                ? null
                : SalesAgentEvent::where('sales_agent_id', $agent->id)->count(),
        ];

        return $withMoney ? $row + ['account' => $this->agents->balance($agent)] : $row;
    }
}
