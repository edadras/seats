<?php

namespace App\Domain\Agents;

use App\Exceptions\ApiException;
use App\Models\AgentCreditEntry;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\SalesAgent;
use App\Models\SalesAgentEvent;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * What an agent may sell, what they have sold, and what is between them and the organiser today.
 *
 * **Credit is money that moved; everything else is counted.** Only a payment in, a settlement out
 * and an adjustment somebody signed are written down. What has been sold, what has been refunded
 * and what commission has been earned are read from the allocations on every request — so a
 * refunded ticket hands its credit straight back, a chargeback does the same, and there is no
 * second column that can quietly disagree with the tickets.
 *
 *     available = paid in − settled out ± adjustments − sold + commission earned + credit limit
 *
 * **A sale is refused before the seats are held, not after the money.** An agent who has run out
 * has run out; finding out at the end of a transaction means a hold to release, a buyer at a
 * counter and a conversation nobody wants to have twice.
 *
 * **The rate is stamped on the booking.** Agreeing a new percentage next season must not rewrite
 * what was owed for this one.
 */
class SalesAgents
{
    public function __construct(private readonly AuditLogger $audit) {}

    /* ------------------------------------------------------------------------ who they are */

    /** The agent this person sells as, where they are one. */
    public function forUser(?User $user): ?SalesAgent
    {
        if (! $user) {
            return null;
        }

        return SalesAgent::where('user_id', $user->id)->first();
    }

    /** Whether an agent may sell a given night. */
    public function maySell(SalesAgent $agent, Event $event): bool
    {
        if (! $agent->active) {
            return false;
        }

        if ($agent->all_events) {
            return true;
        }

        return SalesAgentEvent::where('sales_agent_id', $agent->id)
            ->where('event_id', $event->id)
            ->exists();
    }

    /**
     * The nights this agent may sell, in the order a seller wants them: soonest first.
     *
     * @return \Illuminate\Support\Collection<int, Event>
     */
    public function events(SalesAgent $agent)
    {
        $query = Event::query()->orderBy('starts_at');

        if (! $agent->all_events) {
            $query->whereIn('id', SalesAgentEvent::where('sales_agent_id', $agent->id)->pluck('event_id'));
        }

        return $query->get();
    }

    /**
     * Replace the whole list of what an agent may sell.
     *
     * Wholesale rather than one at a time, for the same reason pricing is: partial edits across a
     * list are where "half the festival is still granted from last season" comes from.
     *
     * @param  list<string>  $eventIds
     */
    public function allow(SalesAgent $agent, array $eventIds, bool $all = false): SalesAgent
    {
        return DB::transaction(function () use ($agent, $eventIds, $all) {
            SalesAgentEvent::where('sales_agent_id', $agent->id)->delete();

            if (! $all) {
                foreach (array_unique($eventIds) as $eventId) {
                    // Resolved through the tenant scope: an event belonging to somebody else is
                    // simply not found, which is also what stops this being used to discover ids.
                    $event = Event::find($eventId);

                    if (! $event) {
                        continue;
                    }

                    SalesAgentEvent::create([
                        'tenant_id' => $agent->tenant_id,
                        'sales_agent_id' => $agent->id,
                        'event_id' => $event->id,
                    ]);
                }
            }

            $agent->forceFill(['all_events' => $all])->save();

            $this->audit->record('agent.allowances_set', $agent, [
                'name' => $agent->name,
                'all_events' => $all,
                'events' => $all ? null : count(array_unique($eventIds)),
            ]);

            return $agent->fresh();
        });
    }

    /* -------------------------------------------------------------------------- the money */

    /**
     * Where an agent stands.
     *
     * @return array{currency: string, paid_in: int, settled_out: int, adjustments: int, sold: int, refunded: int, commission: int, balance: int, credit_limit: int, available: int, orders: int, seats: int}
     */
    public function balance(SalesAgent $agent): array
    {
        $entries = AgentCreditEntry::where('sales_agent_id', $agent->id)->get();

        $paidIn = (int) $entries->where('kind', 'topup')->sum('amount');
        $settled = (int) $entries->where('kind', 'settlement')->sum('amount');
        $adjustments = (int) $entries->where('kind', 'adjustment')->sum('amount');

        $sales = $this->sales($agent);

        /*
         * What the agent owes the organiser, netted.
         *
         * Sold at face value less the commission they keep: an agent on ten per cent who has sold
         * a thousand euros of tickets owes nine hundred, and the ledger should say so rather than
         * making somebody do that subtraction on a Friday.
         */
        $balance = $paidIn + $settled + $adjustments - $sales['sold'] + $sales['commission'];

        return [
            'currency' => $sales['currency'] ?: (string) ($entries->first()->currency ?? config('app.currency', 'EUR')),
            'paid_in' => $paidIn,
            'settled_out' => $settled,
            'adjustments' => $adjustments,
            'sold' => $sales['sold'],
            'refunded' => $sales['refunded'],
            'commission' => $sales['commission'],
            'balance' => $balance,
            'credit_limit' => (int) $agent->credit_limit,
            // What is left to sell against: the balance plus whatever line of credit they were
            // given. This is the number the counter checks, and the only one it checks.
            'available' => $balance + (int) $agent->credit_limit,
            'orders' => $sales['orders'],
            'seats' => $sales['seats'],
        ];
    }

    /**
     * What this agent has sold, counted from the seats rather than from a column.
     *
     * Live allocations only, so a refund, a release or a chargeback takes its money back out of the
     * total the moment it happens. Commission is worked out at the rate stamped on each booking.
     *
     * @return array{sold: int, refunded: int, commission: int, orders: int, seats: int, currency: string}
     */
    public function sales(SalesAgent $agent, ?string $from = null, ?string $to = null): array
    {
        $orders = ExternalOrder::query()
            ->where('sales_agent_id', $agent->id)
            ->whereIn('status', ['confirmed', 'partially_refunded', 'refunded', 'charged_back'])
            // A comp is a gift of the organiser's, not a ticket the agent owes for. Told apart by
            // the same marker the till uses, so the two never disagree about what a free seat is.
            ->whereRaw("coalesce(metadata->>'payment', '') <> 'comp'")
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to))
            ->get(['id', 'currency', 'agent_rate']);

        if ($orders->isEmpty()) {
            return ['sold' => 0, 'refunded' => 0, 'commission' => 0, 'orders' => 0, 'seats' => 0, 'currency' => ''];
        }

        $rows = DB::table('allocations')
            ->selectRaw('external_order_row_id, status, count(*) as seats, coalesce(sum(amount), 0) as amount')
            ->whereIn('external_order_row_id', $orders->pluck('id')->all())
            ->groupBy('external_order_row_id', 'status')
            ->get();

        $sold = 0;
        $refunded = 0;
        $commission = 0;
        $seats = 0;
        $live = [];

        foreach ($rows as $row) {
            $order = $orders->firstWhere('id', $row->external_order_row_id);

            if ('active' === $row->status) {
                $sold += (int) $row->amount;
                $seats += (int) $row->seats;
                $commission += (int) round(((int) $row->amount * (int) ($order->agent_rate ?? 0)) / 10000);
                $live[$row->external_order_row_id] = true;

                continue;
            }

            // Released and void seats are what came back: shown beside the sales because "how much
            // did they sell" and "how much of it stuck" are two different questions.
            $refunded += (int) $row->amount;
        }

        return [
            'sold' => $sold,
            'refunded' => $refunded,
            'commission' => $commission,
            'orders' => count($live),
            'seats' => $seats,
            'currency' => (string) ($orders->first()->currency ?? ''),
        ];
    }

    /**
     * Refuse a sale this agent cannot pay for.
     *
     * Checked before the seats are held rather than after the money: an agent who has run out has
     * run out, and finding that out at the end of a transaction means a hold to release and a
     * queue to apologise to.
     */
    public function assertCanSell(SalesAgent $agent, Event $event, int $amount): void
    {
        if (! $agent->active) {
            throw ApiException::denied('agent_suspended', 'This agent account has been suspended.');
        }

        if (! $this->maySell($agent, $event)) {
            throw ApiException::denied(
                'agent_event_not_allowed',
                'This agent has not been given this event to sell.',
            );
        }

        if ($amount < 1) {
            return; // A comp costs the agent nothing, and nought against a limit is not a refusal.
        }

        $state = $this->balance($agent);
        $net = $amount - (int) round(($amount * (int) $agent->commission_rate) / 10000);

        if ($net > $state['available']) {
            throw ApiException::conflict(
                'agent_credit_exhausted',
                'This sale is more than the agent has left to sell against.',
                ['available' => $state['available'], 'needed' => $net],
            );
        }
    }

    /** Record money moving between the organiser and an agent. */
    public function record(SalesAgent $agent, array $data, ?User $by = null): AgentCreditEntry
    {
        $kind = $data['kind'] ?? 'topup';
        $amount = (int) ($data['amount'] ?? 0);

        if (! in_array($kind, AgentCreditEntry::KINDS, true)) {
            throw ApiException::unprocessable('agent_entry_kind', 'That is not a kind of movement.');
        }

        if (0 === $amount) {
            throw ApiException::unprocessable('agent_entry_needs_amount', 'Say how much moved.');
        }

        /*
         * Direction is decided here rather than left to the caller's sign.
         *
         * A top-up is money in and a settlement is money out, always; a panel that could send a
         * negative top-up is a panel where a typo pays an agency instead of charging it. An
         * adjustment is the one that keeps its sign, because that is what an adjustment is.
         */
        $signed = match ($kind) {
            'topup' => abs($amount),
            'settlement' => -abs($amount),
            default => $amount,
        };

        $entry = AgentCreditEntry::create([
            'tenant_id' => $agent->tenant_id,
            'sales_agent_id' => $agent->id,
            'kind' => $kind,
            'amount' => $signed,
            'currency' => mb_strtoupper((string) ($data['currency'] ?? $this->balance($agent)['currency'] ?: 'EUR')),
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'recorded_by' => $by?->id,
        ]);

        $this->audit->record('agent.credit_recorded', $agent, [
            'name' => $agent->name,
            'kind' => $kind,
            'amount' => $signed,
            'reference' => $data['reference'] ?? null,
        ]);

        return $entry;
    }

    /**
     * The statement: what an agent sold in a period, what it earned them, and what is outstanding.
     *
     * The period bounds the sales, never the balance. What is between the two parties is what is
     * between them now — a statement for March that showed March's balance would be a number
     * nobody can pay.
     */
    public function statement(SalesAgent $agent, ?string $from = null, ?string $to = null): array
    {
        $period = $this->sales($agent, $from, $to);
        $state = $this->balance($agent);

        return [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'code' => $agent->code,
                'commission_rate' => (int) $agent->commission_rate,
            ],
            'from' => $from,
            'to' => $to,
            'period' => [
                'sold' => $period['sold'],
                'refunded' => $period['refunded'],
                'commission' => $period['commission'],
                'due' => $period['sold'] - $period['commission'],
                'orders' => $period['orders'],
                'seats' => $period['seats'],
            ],
            'account' => $state,
        ];
    }

    /** Every movement, newest first, for the screen that shows an agent's account. */
    public function ledger(SalesAgent $agent, int $limit = 100): array
    {
        return AgentCreditEntry::where('sales_agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->map(fn (AgentCreditEntry $entry) => [
                'id' => $entry->id,
                'kind' => $entry->kind,
                'amount' => (int) $entry->amount,
                'currency' => $entry->currency,
                'method' => $entry->method,
                'reference' => $entry->reference,
                'note' => $entry->note,
                'at' => $entry->created_at?->toIso8601String(),
            ])->values()->all();
    }
}
