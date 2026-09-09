<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Customers\CustomerDirectory;
use App\Domain\Privacy\PersonalData;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The people who have bought tickets from this organiser, and what each of them bought.
 *
 * `orders.view` throughout, not a permission of its own: this is the box office's own data seen
 * from the buyer's side rather than the order's, and a role that may open an order may already
 * read every name and address on this screen. Inventing a second permission for the same facts
 * would only let an account believe it had withheld something it had not.
 *
 * The directory is derived from the orders themselves (see CustomerDirectory), so it cannot drift
 * from them, and a person is addressed by the SHA-256 of their email rather than by the address:
 * an email in a path is an email in an access log.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerDirectory $directory,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'orders.view');

        $filters = $this->filters($request);
        $perPage = min(100, max(5, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));

        $result = $this->directory->page($filters, $perPage, $page);

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
                'currency' => $result['currency'],
                'currencies' => $result['currencies'],
                'without_email' => $result['without_email'],
            ],
        ]);
    }

    public function show(Request $request, string $customer)
    {
        $this->authorize($request, 'orders.view');

        $found = $this->directory->find($customer);

        if (! $found) {
            throw new NotFoundHttpException('No such customer.');
        }

        return response()->json($found);
    }

    /**
     * The list as a file.
     *
     * Audited by name, because this is the one request that takes every buyer's address out of the
     * platform in one go, and "who exported the customer list, and when" is a question an organiser
     * will one day have to answer to somebody else.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize($request, 'orders.view');

        $filters = $this->filters($request);
        $result = $this->directory->all($filters);

        $this->audit->record('customers.exported', null, [
            'rows' => count($result['rows']),
            'query' => $filters['q'] ?? null,
            'event_id' => $filters['event_id'] ?? null,
        ]);

        $headings = [
            __('panel.customers.name'),
            __('panel.customers.email'),
            __('panel.customers.phone'),
            __('panel.customers.orders'),
            __('panel.customers.paidOrders'),
            __('panel.customers.events'),
            __('panel.customers.seats'),
            __('panel.customers.firstOrder'),
            __('panel.customers.lastOrder'),
            __('panel.customers.spend'),
            __('panel.customers.currency'),
        ];

        return response()->streamDownload(function () use ($result, $headings) {
            $handle = fopen('php://output', 'wb');

            // The same BOM the report export writes, for the same reason: a spreadsheet on Windows
            // opens Persian and Arabic headings as text rather than as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headings);

            foreach ($result['rows'] as $row) {
                fputcsv($handle, [
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $row['phone'] ?? '',
                    $row['orders_count'],
                    $row['confirmed_count'],
                    $row['events_count'],
                    $row['seats_count'],
                    $this->day($row['first_order_at'] ?? null),
                    $this->day($row['last_order_at'] ?? null),
                    // Minor units, as they are stored: a spreadsheet that divides by a hundred is
                    // a spreadsheet that has guessed which currencies have decimals.
                    $row['spend_in_currency'] ?? 0,
                    $result['currency'] ?? '',
                ]);
            }

            fclose($handle);
        }, 'customers.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* --------------------------------------------------------------------------- helpers */

    /**
     * Everything held about one person, as a file they can be handed.
     *
     * Behind `account.manage` rather than `orders.view`: a box office needs to find a booking, and
     * this is every address, answer and message in one document — a different thing to be trusted
     * with.
     */
    public function personalData(Request $request, string $customer)
    {
        $this->authorize($request, 'account.manage');

        $person = $this->directory->find($customer);

        if (! $person) {
            throw new NotFoundHttpException('No such customer.');
        }

        $data = app(PersonalData::class)->export($person['email']);

        $this->audit->record('privacy.exported', null, ['orders' => count($data['orders'])]);

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="personal-data.json"',
            // Somebody's whole history with this organiser. Nothing in between keeps a copy.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Take the person out of the record and leave the record.
     *
     * Not deletion: the amounts, the dates and the seats stay, because an organiser still has to be
     * able to tell a tax authority what last March came to, and "somebody asked us to delete it" is
     * not an answer a tax authority takes. What goes is everything that says who it was.
     */
    public function erase(Request $request, string $customer)
    {
        $this->authorize($request, 'account.manage');

        // Typed back rather than clicked through: this cannot be undone, and a confirmation
        // dialogue is not a decision.
        $request->validate(['confirm' => ['required', 'in:erase']]);

        $person = $this->directory->find($customer);

        if (! $person) {
            throw new NotFoundHttpException('No such customer.');
        }

        return response()->json(['erased' => app(PersonalData::class)->erase($person['email'])]);
    }

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'event_id' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'string', 'in:pending,confirmed,cancelled,refunded,partially_refunded'],
            'sort' => ['nullable', 'string', 'in:recent,oldest,orders,spend,name'],
        ]);

        return array_filter($data, fn ($value) => null !== $value && '' !== $value);
    }

    private function day(?string $iso): string
    {
        return $iso ? Date::parse($iso)->toDateString() : '';
    }
}
