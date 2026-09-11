<?php

namespace App\Domain\Payments;

use App\Exceptions\ApiException;
use App\Models\ExternalOrder;
use App\Models\OrderInstalment;
use App\Models\Site;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A deposit today and the balance by a date somebody agreed on the telephone.
 *
 * **The plan must add up to the price.** Refused otherwise, and refused loudly: a schedule that
 * comes to less than the booking is a debt nobody agreed to, and one that comes to more is an
 * overcharge that will be discovered by the person who paid it.
 *
 * **The seats go at the deposit and the tickets go at the last payment.** Those are two promises
 * and running them together is how a party arrives with forty codes they never paid for. The
 * chairs are allocated as the booking is made — nobody else can have them — and the code that
 * opens a door is minted when the money is all in.
 *
 * **Nothing is stored that can be counted.** The balance is the unpaid instalments; overdue is a
 * comparison against the clock at the moment somebody asks. No column goes stale, and no job has
 * to run at midnight for a booking to become late.
 */
class PaymentPlans
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly \App\Domain\Orders\TicketIssuer $tickets,
        private readonly \App\Domain\Sites\TicketMailer $mail,
    ) {}

    /**
     * Agree a plan against a booking.
     *
     * `$spec` is either an explicit `schedule` — a list of `{amount, due_on}`, which is what a
     * panel sends once somebody has moved a date — or the shape of the conversation: a `deposit`,
     * how many `instalments` follow it and how many days apart they are.
     *
     * @param  array{schedule?: list<array{amount: int|string, due_on: string}>, deposit?: int, instalments?: int, every_days?: int, first_due_on?: string|null, deposit_paid?: bool, method?: string|null}  $spec
     * @return list<OrderInstalment>
     */
    public function create(ExternalOrder $order, array $spec, ?User $by = null): array
    {
        if ($this->instalments($order)->isNotEmpty()) {
            throw ApiException::conflict(
                'plan_already_agreed',
                'This booking already has a payment plan. Change the plan rather than adding a second.',
            );
        }

        $schedule = $spec['schedule'] ?? null
            ? $this->given($spec['schedule'])
            : $this->spread($order, $spec);

        $this->mustAddUp($order, $schedule);

        return DB::transaction(function () use ($order, $schedule, $spec, $by) {
            $rows = [];

            foreach ($schedule as $index => $step) {
                $rows[] = OrderInstalment::create([
                    'tenant_id' => $order->tenant_id,
                    'external_order_row_id' => $order->id,
                    'sequence' => $index,
                    'kind' => 0 === $index ? 'deposit' : 'instalment',
                    'amount' => $step['amount'],
                    'due_on' => $step['due_on'],
                    // The deposit is ordinarily taken as the booking is made, and saying so here
                    // saves a clerk recording a payment they have already put in the drawer.
                    'paid_at' => 0 === $index && ($spec['deposit_paid'] ?? false) ? now() : null,
                    'method' => 0 === $index && ($spec['deposit_paid'] ?? false)
                        ? ($spec['method'] ?? null)
                        : null,
                    'recorded_by' => 0 === $index && ($spec['deposit_paid'] ?? false) ? $by?->id : null,
                ]);
            }

            if (($spec['deposit_paid'] ?? false) && isset($rows[0]) && $rows[0]->isPaid()) {
                $this->intoDrawer($order, (int) $rows[0]->amount, $spec['method'] ?? null, $by);
            }

            $this->audit->record('order.plan_agreed', $order, [
                'reference' => $order->external_order_id,
                'instalments' => count($rows),
                'total' => array_sum(array_column($schedule, 'amount')),
            ]);

            // A plan paid off in full the moment it was agreed — a single instalment marked paid —
            // is a booking that owes nothing, and its tickets are due now.
            $this->settleIfPaid($order->fresh());

            return $rows;
        });
    }

    /**
     * Record that one instalment has been paid.
     *
     * Idempotent by the row: a payment entered by two clerks on the telephone is one payment,
     * because the second finds it already marked and changes nothing.
     */
    public function pay(OrderInstalment $instalment, array $data = [], ?User $by = null): OrderInstalment
    {
        if ($instalment->isPaid()) {
            return $instalment;
        }

        $order = ExternalOrder::find($instalment->external_order_row_id);

        if (! $order) {
            throw ApiException::notFound('Unknown booking.', 'unknown_order');
        }

        DB::transaction(function () use ($instalment, $data, $by, $order) {
            // Locked and re-read: two clerks pressing the same button at the same moment must
            // produce one payment and one movement in the drawer, not two of each.
            $held = OrderInstalment::whereKey($instalment->id)->lockForUpdate()->first();

            if (! $held || $held->isPaid()) {
                return;
            }

            $held->forceFill([
                'paid_at' => now(),
                'method' => $data['method'] ?? null,
                'note' => $data['note'] ?? null,
                'recorded_by' => $by?->id,
            ])->save();

            $instalment->setRawAttributes($held->getAttributes(), true);

            $this->intoDrawer($order, (int) $held->amount, $data['method'] ?? null, $by);

            $this->audit->record('order.instalment_paid', $order, [
                'reference' => $order->external_order_id,
                'sequence' => (int) $held->sequence,
                'amount' => (int) $held->amount,
                'method' => $data['method'] ?? null,
            ]);
        });

        $this->settleIfPaid($order->fresh());

        return $instalment->refresh();
    }

    /** Move a date or an amount on a step nobody has paid yet. */
    public function amend(OrderInstalment $instalment, array $data, ?User $by = null): OrderInstalment
    {
        if ($instalment->isPaid()) {
            throw ApiException::conflict(
                'instalment_already_paid',
                'That payment has been taken. Refund it rather than rewriting it.',
            );
        }

        $order = ExternalOrder::find($instalment->external_order_row_id);
        $amount = array_key_exists('amount', $data) ? (int) $data['amount'] : (int) $instalment->amount;

        if ($order && $amount !== (int) $instalment->amount) {
            $others = $this->instalments($order)
                ->reject(fn (OrderInstalment $row) => $row->id === $instalment->id)
                ->sum('amount');

            if ($others + $amount !== (int) $order->total_amount) {
                throw $this->wontAddUp($order, $others + $amount);
            }
        }

        $instalment->forceFill(array_filter([
            'amount' => $amount,
            'due_on' => $data['due_on'] ?? $instalment->due_on,
            'note' => $data['note'] ?? $instalment->note,
        ]))->save();

        if ($order) {
            $this->audit->record('order.instalment_amended', $order, [
                'reference' => $order->external_order_id,
                'sequence' => (int) $instalment->sequence,
                'amount' => $amount,
                'due_on' => (string) $instalment->due_on?->toDateString(),
            ]);
        }

        return $instalment->refresh();
    }

    /**
     * Where a booking stands.
     *
     * @return array{has_plan: bool, total: int, paid: int, balance: int, state: string, next_due_on: ?string, instalments: list<array<string, mixed>>}
     */
    public function state(ExternalOrder $order): array
    {
        $rows = $this->instalments($order);

        if ($rows->isEmpty()) {
            return [
                'has_plan' => false,
                'total' => (int) $order->total_amount,
                'paid' => (int) $order->total_amount,
                'balance' => 0,
                'state' => 'settled',
                'next_due_on' => null,
                'instalments' => [],
            ];
        }

        $paid = (int) $rows->where('paid_at', '!=', null)->sum('amount');
        $balance = (int) $rows->whereNull('paid_at')->sum('amount');
        $late = $rows->first(fn (OrderInstalment $row) => $row->isOverdue());
        $next = $rows->whereNull('paid_at')->sortBy('due_on')->first();

        return [
            'has_plan' => true,
            'total' => (int) $rows->sum('amount'),
            'paid' => $paid,
            'balance' => $balance,
            'state' => 0 === $balance ? 'settled' : ($late ? 'overdue' : 'due'),
            'next_due_on' => $next?->due_on?->toDateString(),
            'instalments' => $rows->map(fn (OrderInstalment $row) => [
                'id' => $row->id,
                'sequence' => (int) $row->sequence,
                'kind' => $row->kind,
                'amount' => (int) $row->amount,
                'due_on' => $row->due_on?->toDateString(),
                'paid_at' => $row->paid_at?->toIso8601String(),
                'method' => $row->method,
                'note' => $row->note,
                'state' => $row->isPaid() ? 'paid' : ($row->isOverdue() ? 'overdue' : 'due'),
            ])->values()->all(),
        ];
    }

    /** Does this booking still owe anything? Asked wherever a ticket is about to be minted. */
    public function owes(ExternalOrder $order): bool
    {
        return $this->instalments($order)->whereNull('paid_at')->isNotEmpty();
    }

    /**
     * The chase list: every booking with money still to come.
     *
     * `$state` is `overdue`, `due` or `all`. Ordered by the date somebody should have paid, because
     * that is the order a person works down a list on a Monday morning.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @param  list<string>|null  $eventIds  the nights to keep to, or null for every night
     */
    public function outstanding(string $state = 'all', int $limit = 200, ?array $eventIds = null): array
    {
        $rows = OrderInstalment::query()
            ->whereNull('paid_at')
            /*
             * An instalment belongs to a booking, and a booking to a night, so a programme manager's
             * list is reached through the order rather than off the row. Null is everybody else and
             * means no restriction; an empty list means they run nothing and see nothing.
             */
            ->when(null !== $eventIds, fn ($query) => $query->whereIn(
                'external_order_row_id',
                ExternalOrder::whereIn('event_id', $eventIds)->pluck('id')
            ))
            ->orderBy('due_on')
            ->limit(max(1, min(500, $limit)))
            ->get();

        if ('overdue' === $state) {
            $rows = $rows->filter(fn (OrderInstalment $row) => $row->isOverdue());
        } elseif ('due' === $state) {
            $rows = $rows->reject(fn (OrderInstalment $row) => $row->isOverdue());
        }

        $orders = ExternalOrder::with('event')
            ->whereIn('id', $rows->pluck('external_order_row_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return $rows->map(function (OrderInstalment $row) use ($orders) {
            $order = $orders->get($row->external_order_row_id);

            return [
                'id' => $row->id,
                'order_id' => $row->external_order_row_id,
                'reference' => $order?->external_order_id,
                'group_name' => $order?->group_name,
                'buyer' => $order?->buyer['name'] ?? null,
                'email' => $order?->buyer['email'] ?? null,
                'event' => $order?->event?->name,
                'currency' => $order?->currency,
                'amount' => (int) $row->amount,
                'due_on' => $row->due_on?->toDateString(),
                'state' => $row->isOverdue() ? 'overdue' : 'due',
                // What the whole booking still owes, not just this step: a school three payments
                // behind is a different telephone call from one that is a week late.
                'balance' => $order ? (int) $this->instalments($order)->whereNull('paid_at')->sum('amount') : 0,
            ];
        })->values()->all();
    }

    /** @return \Illuminate\Support\Collection<int, OrderInstalment> */
    public function instalments(ExternalOrder $order): \Illuminate\Support\Collection
    {
        return OrderInstalment::where('external_order_row_id', $order->id)
            ->orderBy('sequence')
            ->get();
    }

    /* --------------------------------------------------------------------------- internals */

    /**
     * Cash into the drawer, as a movement rather than as takings.
     *
     * The sale was counted on the evening it was made; what arrives with an instalment is money
     * for a reason that is not a sale, which is exactly what a movement is. Counted as takings it
     * would make the evening of the balance look like the evening of the booking.
     */
    private function intoDrawer(ExternalOrder $order, int $amount, ?string $method, ?User $by): void
    {
        if ('cash' !== $method || ! $by || $amount < 1) {
            return;
        }

        $tills = app(\App\Domain\BoxOffice\Tills::class);
        $shift = $tills->current($by);

        if ($shift) {
            $tills->move(
                $shift,
                'in',
                $amount,
                trim(__('panel.plans.tillReason').' '.$order->external_order_id),
                $by->id,
            );
        }
    }

    /**
     * The tickets, once the last payment is in.
     *
     * Minted here rather than at the sale, and sent where there is an address to send them to: the
     * party has had the seats since the deposit, and this is the part that opens a door.
     */
    private function settleIfPaid(ExternalOrder $order): void
    {
        if ('confirmed' !== $order->status || $this->owes($order)) {
            return;
        }

        $order->loadMissing('allocations.ticket');
        $minted = false;

        foreach ($order->allocations as $allocation) {
            if ('active' !== $allocation->status || $allocation->ticket) {
                continue;
            }

            $this->tickets->issue($allocation, $order->buyer['name'] ?? null);
            $minted = true;
        }

        if (! $minted) {
            return;
        }

        $this->audit->record('order.plan_settled', $order, [
            'reference' => $order->external_order_id,
            'seats' => $order->allocations->count(),
        ]);

        $site = Site::where('tenant_id', $order->tenant_id)->orderBy('created_at')->first();

        if ($site && ! empty($order->buyer['email'])) {
            // The tokens exist only in memory on the models that were just issued, so the mailer
            // is handed the freshly loaded order rather than the one this method started with.
            $this->mail->send($site, $order->fresh(['allocations.ticket', 'event']));
        }
    }

    /**
     * A schedule somebody typed.
     *
     * @param  list<array{amount: int|string, due_on: string}>  $schedule
     * @return list<array{amount: int, due_on: string}>
     */
    private function given(array $schedule): array
    {
        if (count($schedule) < 1 || count($schedule) > 24) {
            throw ApiException::unprocessable(
                'plan_needs_steps',
                'A plan needs between one and twenty-four payments.',
            );
        }

        return array_values(array_map(fn (array $step) => [
            'amount' => (int) $step['amount'],
            'due_on' => Carbon::parse($step['due_on'])->toDateString(),
        ], $schedule));
    }

    /**
     * A deposit, then equal payments a fixed number of days apart.
     *
     * The remainder lands on the first instalment rather than being scattered: somebody reading a
     * plan should see one odd number, not five.
     *
     * @return list<array{amount: int, due_on: string}>
     */
    private function spread(ExternalOrder $order, array $spec): array
    {
        $total = (int) $order->total_amount;
        $deposit = max(0, min($total, (int) ($spec['deposit'] ?? 0)));
        $count = max(1, min(24, (int) ($spec['instalments'] ?? 1)));
        $every = max(1, min(365, (int) ($spec['every_days'] ?? 30)));
        $first = Carbon::parse($spec['first_due_on'] ?? now()->addDays($every))->startOfDay();

        $rest = $total - $deposit;

        if ($rest < 1) {
            return [['amount' => $total, 'due_on' => now()->toDateString()]];
        }

        $each = intdiv($rest, $count);
        $schedule = [['amount' => $deposit, 'due_on' => now()->toDateString()]];

        for ($step = 0; $step < $count; $step++) {
            $schedule[] = [
                'amount' => 0 === $step ? $each + ($rest - $each * $count) : $each,
                'due_on' => $first->copy()->addDays($every * $step)->toDateString(),
            ];
        }

        // A plan with no deposit is a plan, not a plan with a nought in front of it.
        return array_values(array_filter($schedule, fn (array $step) => $step['amount'] > 0));
    }

    /** @param  list<array{amount: int, due_on: string}>  $schedule */
    private function mustAddUp(ExternalOrder $order, array $schedule): void
    {
        $sum = array_sum(array_column($schedule, 'amount'));

        if ($sum !== (int) $order->total_amount) {
            throw $this->wontAddUp($order, $sum);
        }

        foreach ($schedule as $step) {
            if ($step['amount'] < 1) {
                throw ApiException::unprocessable(
                    'plan_needs_amounts',
                    'Every payment in a plan has to be for something.',
                );
            }
        }
    }

    private function wontAddUp(ExternalOrder $order, int $sum): ApiException
    {
        return ApiException::unprocessable(
            'plan_does_not_add_up',
            'The payments have to come to what the booking costs.',
            ['total' => (int) $order->total_amount, 'scheduled' => $sum],
        );
    }
}
