<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Risk\Blocklist;
use App\Domain\Risk\Chargebacks;
use App\Http\Controllers\Controller;
use App\Models\BlockedBuyer;
use App\Models\ExternalOrder;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * Money taken back, and the people who may not buy again.
 *
 * Recording a chargeback is behind `orders.refund`: it is the same authority as giving money back,
 * because it does the same thing to the seats. The blocklist is behind `account.manage` — barring a
 * person from an organiser is not a box-office decision, and the audit log names whoever did it.
 */
class RiskController extends Controller
{
    public function __construct(
        private readonly Blocklist $blocklist,
        private readonly Chargebacks $chargebacks,
        private readonly AuditLogger $audit,
    ) {}

    public function chargeback(Request $request, ExternalOrder $order)
    {
        $this->authorize($request, 'orders.refund');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
            // What the bank charged for handling it. A real cost, and it belongs in the takings
            // rather than in somebody's memory of a bad month.
            'fee' => ['sometimes', 'integer', 'min:0'],
            // Never automatic: a disputed payment is sometimes a stolen card and sometimes a buyer
            // who could not reach anybody about a cancelled train.
            'block' => ['sometimes', 'boolean'],
        ]);

        $updated = $this->chargebacks->record(
            $order,
            $data['reason'],
            (int) ($data['fee'] ?? 0),
            (bool) ($data['block'] ?? false),
            $request->user()?->id,
        );

        $this->audit->record('order.charged_back', $updated, [
            'reference' => $updated->external_order_id,
            'reason' => $data['reason'],
            'blocked' => (bool) ($data['block'] ?? false),
        ]);

        return response()->json([
            'id' => $updated->id,
            'reference' => $updated->external_order_id,
            'status' => $updated->status,
            'charged_back_at' => $updated->charged_back_at?->toIso8601String(),
            'chargeback_fee' => (int) $updated->chargeback_fee,
            'chargeback_reason' => $updated->chargeback_reason,
            // What it cost the organiser in seats, which is the part they will be asked about.
            'seats_released' => $updated->allocations->where('status', 'released')->count(),
        ]);
    }

    public function blocks(Request $request)
    {
        $this->authorize($request, 'account.manage');

        return response()->json([
            'data' => BlockedBuyer::query()->orderByDesc('created_at')->limit(500)->get()
                ->map(fn (BlockedBuyer $block) => $this->present($block))->all(),
        ]);
    }

    public function block(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            // Required, because a block nobody wrote a reason for is a block nobody can defend a
            // year later — least of all to the person it is about.
            'reason' => ['required', 'string', 'max:500'],
            'until' => ['nullable', 'date', 'after:now'],
        ]);

        $block = $this->blocklist->add(
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['reason'],
            ($data['until'] ?? null) ? \Illuminate\Support\Carbon::parse($data['until']) : null,
            $request->user()?->id,
        );

        $this->audit->record('buyer.blocked', $block, ['reason' => $data['reason']]);

        return response()->json($this->present($block), 201);
    }

    public function unblock(Request $request, BlockedBuyer $blockedBuyer)
    {
        $this->authorize($request, 'account.manage');

        $this->audit->record('buyer.unblocked', $blockedBuyer, [
            'email' => $blockedBuyer->email,
            'phone' => $blockedBuyer->phone,
        ]);

        $blockedBuyer->delete();

        return response()->noContent();
    }

    private function present(BlockedBuyer $block): array
    {
        return [
            'id' => $block->id,
            'email' => $block->email,
            'phone' => $block->phone,
            'reason' => $block->reason,
            'until' => $block->until?->toIso8601String(),
            'in_force' => $block->inForce(),
            'created_at' => $block->created_at?->toIso8601String(),
        ];
    }
}
