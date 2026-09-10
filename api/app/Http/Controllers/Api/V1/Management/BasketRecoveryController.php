<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Baskets\Baskets;
use App\Domain\Messaging\MessageDispatcher;
use App\Domain\Messaging\OrderMessages;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BasketRecovery;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Purchases somebody started and did not finish, and what came of writing to them.
 *
 * Behind `orders.view`, because that is what this is: bookings that did not happen, listed by
 * buyer. Sending a message by hand takes `messages.send` as well — the list is a report, and
 * writing to somebody is not.
 *
 * The number the screen exists for is the last one: of the people written to, how many came back.
 * Everything else here is the working behind it.
 */
class BasketRecoveryController extends Controller
{
    public function __construct(
        private readonly Baskets $baskets,
        private readonly OrderMessages $messages,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'orders.view');

        $recoveries = BasketRecovery::query()
            ->with(['event:id,name,starts_at,timezone', 'order:id,external_order_id,status', 'recoveredOrder:id,external_order_id'])
            ->when($request->query('event_id'), fn ($q, $id) => $q->where('event_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('email', 'ilike', "%{$term}%")
                    ->orWhere('name', 'ilike', "%{$term}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        // The summary rides with the list rather than living on its own endpoint: the screen shows
        // both at once, and two requests to draw one page is one request too many.
        return response()->json([
            'data' => $recoveries->getCollection()
                ->map(fn (BasketRecovery $recovery) => $this->present($recovery))
                ->values(),
            'meta' => [
                'page' => $recoveries->currentPage(),
                'per_page' => $recoveries->perPage(),
                'total' => $recoveries->total(),
                'last_page' => $recoveries->lastPage(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    /**
     * Write to one of them now, rather than waiting for the hourly pass.
     *
     * Only to somebody who has not been written to: this is a way of not waiting an hour, not a
     * way of writing twice.
     */
    public function send(Request $request, BasketRecovery $basketRecovery)
    {
        $this->authorize($request, 'messages.send');

        if ('waiting' !== $basketRecovery->status) {
            throw ApiException::conflict(
                'basket_already_written',
                'This buyer has already been written to about this basket.'
            );
        }

        /*
         * The kind has to be switched on, and this refuses rather than quietly sending nothing.
         *
         * A button that reports success and delivers no message is worse than one that explains
         * itself: the organiser would find out weeks later, from a recovery rate of zero.
         */
        if ([] === $this->dispatcher->enabledChannels('order.unfinished')) {
            throw ApiException::unprocessable(
                'basket_message_off',
                'Switch "Unfinished booking" on under Messages first — nothing is sent until you do.'
            );
        }

        $site = $basketRecovery->site_id ? Site::find($basketRecovery->site_id) : null;
        $order = $basketRecovery->order;

        if (! $site || ! $order) {
            throw ApiException::unprocessable(
                'basket_has_no_site',
                'There is nowhere to send this buyer back to.'
            );
        }

        $this->messages->unfinished(
            $order,
            $site->url('/basket/'.$basketRecovery->token),
            $site->url('/basket/'.$basketRecovery->token.'/no-thanks'),
        );

        $basketRecovery->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        return response()->json($this->present($basketRecovery->fresh(['event', 'order'])));
    }

    /**
     * The four numbers, and the one that matters.
     *
     * Money is summed per currency, because an account selling in two of them has two answers and
     * a column that added rial to euro would be a number nobody could bank.
     *
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $rows = DB::table('basket_recoveries')
            ->groupBy('status', 'currency')
            ->selectRaw('status, currency, count(*) as rows, sum(total_amount) as value')
            ->get();

        $counts = ['waiting' => 0, 'sent' => 0, 'recovered' => 0, 'expired' => 0, 'declined' => 0];
        $lost = [];
        $won = [];

        foreach ($rows as $row) {
            $counts[$row->status] = ($counts[$row->status] ?? 0) + (int) $row->rows;

            if ('recovered' === $row->status) {
                $won[$row->currency] = ($won[$row->currency] ?? 0) + (int) $row->value;
            } elseif (in_array($row->status, ['expired', 'declined'], true)) {
                $lost[$row->currency] = ($lost[$row->currency] ?? 0) + (int) $row->value;
            }
        }

        // Of the people actually written to. Counting the ones nobody wrote to would make the
        // number look worse the better the organiser's checkout got, which is exactly backwards.
        $written = $counts['sent'] + $counts['recovered'] + $counts['expired'] + $counts['declined'];

        return [
            'baskets' => array_sum($counts),
            'waiting' => $counts['waiting'],
            'written' => $written,
            'recovered' => $counts['recovered'],
            'declined' => $counts['declined'],
            'rate' => $written > 0 ? (int) round($counts['recovered'] * 100 / $written) : 0,
            'won' => $this->money($won),
            'lost' => $this->money($lost),
        ];
    }

    /** @param  array<string, int>  $byCurrency */
    private function money(array $byCurrency): array
    {
        $out = [];

        foreach ($byCurrency as $currency => $amount) {
            $out[] = ['currency' => $currency, 'amount' => $amount];
        }

        return $out;
    }

    private function present(BasketRecovery $recovery): array
    {
        return [
            'id' => $recovery->id,
            'email' => $recovery->email,
            'name' => $recovery->name,
            'event_id' => $recovery->event_id,
            'event_name' => $recovery->event?->name,
            'seats' => (int) $recovery->seats,
            'total' => (int) $recovery->total_amount,
            'currency' => $recovery->currency,
            'status' => $recovery->status,
            'reference' => $recovery->order?->external_order_id,
            'recovered_reference' => $recovery->recoveredOrder?->external_order_id,
            'sent_at' => $recovery->sent_at?->toIso8601String(),
            'recovered_at' => $recovery->recovered_at?->toIso8601String(),
            'created_at' => $recovery->created_at?->toIso8601String(),
        ];
    }
}
