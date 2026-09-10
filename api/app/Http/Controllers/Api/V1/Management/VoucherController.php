<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Vouchers\Vouchers;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gift vouchers and account credit, from the organiser's side.
 *
 * Behind `vouchers.manage` rather than `discounts.manage`, because the two are different powers.
 * Inventing a half-price code costs the organiser a margin; issuing a voucher hands somebody money.
 *
 * There is no update and no delete, and that is the design rather than an omission. A voucher's
 * amount is its `issue` movement and its balance is the sum of the movements — editing the amount
 * afterwards would mean a balance that disagrees with the ledger under it, and deleting one that
 * has paid for a booking would leave that booking claiming it was settled out of nothing. The way
 * a voucher is stopped is `void`, which writes the write-off row that says where the money went.
 */
class VoucherController extends Controller
{
    public function __construct(private readonly Vouchers $vouchers) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        $vouchers = Voucher::query()
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('code', 'ilike', "%{$term}%")
                    ->orWhere('email', 'ilike', "%{$term}%")
                    ->orWhere('recipient', 'ilike', "%{$term}%")
                    ->orWhere('note', 'ilike', "%{$term}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->paginated($vouchers, fn (Voucher $voucher) => $this->present($voucher));
    }

    /** A code nobody has to invent, in an alphabet nobody has to read twice. */
    public function suggest(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        do {
            $code = Voucher::suggest();
        } while (Voucher::where('code', $code)->exists());

        return response()->json(['code' => $code]);
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        $data = $request->validate([
            'kind' => ['required', Rule::in(Voucher::KINDS)],
            // Only for a gift. Credit is named, and a code on it would make it bearer.
            'code' => ['required_if:kind,gift', 'nullable', 'string', 'max:40', 'regex:/^[\pL\pN._-]+$/u'],
            'email' => ['required_if:kind,credit', 'nullable', 'email', 'max:190'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'currency' => ['required', 'string', 'size:3'],
            'note' => ['nullable', 'string', 'max:200'],
            'recipient' => ['nullable', 'string', 'max:160'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $gift = 'gift' === $data['kind'];

        // Exactly one identifying column, matching the constraint the database will apply anyway.
        // Refused here rather than there so the answer is a sentence and not a driver exception.
        $data['code'] = $gift ? Voucher::normalise((string) $data['code']) : null;
        $data['email'] = $gift ? null : trim(mb_strtolower((string) $data['email']));
        $data['currency'] = mb_strtoupper($data['currency']);

        if ($gift && Voucher::where('code', $data['code'])->exists()) {
            throw ApiException::conflict('voucher_code_taken', 'You already have a voucher with that code.');
        }

        $voucher = $this->vouchers->issue($data + [
            'tenant_id' => $request->user()?->tenant_id,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json($this->present($voucher), 201);
    }

    public function show(Request $request, Voucher $voucher)
    {
        $this->authorize($request, 'vouchers.manage');

        return response()->json($this->present($voucher) + [
            // The movements behind the balance. A screen that shows a number and cannot say where
            // it came from is a screen somebody has to phone support about.
            'movements' => $this->vouchers->history($voucher),
        ]);
    }

    /**
     * Stop a voucher and write off what is left.
     *
     * A POST rather than a DELETE, because nothing is deleted: the row stays, the ledger stays, and
     * one more movement takes the remaining balance to zero.
     */
    public function void(Request $request, Voucher $voucher)
    {
        $this->authorize($request, 'vouchers.manage');

        if ('void' === $voucher->status) {
            return response()->json($this->present($voucher));
        }

        $why = $request->validate(['reason' => ['nullable', 'string', 'max:200']])['reason'] ?? null;

        return response()->json($this->present($this->vouchers->void($voucher, $why)));
    }

    private function present(Voucher $voucher): array
    {
        $balance = $this->vouchers->balance($voucher);

        return [
            'id' => $voucher->id,
            'kind' => $voucher->kind,
            'code' => $voucher->code,
            'email' => $voucher->email,
            'amount' => (int) $voucher->amount,
            // Derived, every time it is asked for. There is no column to read this off.
            'balance' => $balance,
            'spent' => (int) $voucher->amount - $balance,
            'currency' => $voucher->currency,
            'note' => $voucher->note,
            'recipient' => $voucher->recipient,
            'expires_at' => $voucher->expires_at?->toIso8601String(),
            'status' => $voucher->status,
            'live' => $voucher->isLive() && $balance > 0,
            'created_at' => $voucher->created_at?->toIso8601String(),
        ];
    }
}
