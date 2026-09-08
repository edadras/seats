<?php

namespace App\Console\Commands;

use App\Domain\Inventory\HoldService;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Attempts a single hold and reports the outcome as JSON.
 *
 * Exists so the concurrency test can run genuinely parallel OS processes against one Postgres
 * instance. In-process threads or a forked test runner would share connections and transaction
 * state, which is precisely what must not be shared when proving that the database — not the
 * application — decides who wins a contested seat.
 */
class AttemptHold extends Command
{
    protected $signature = 'seatmap:attempt-hold
        {event : Event id}
        {seats : Comma-separated seat ids}
        {--session=concurrency-probe}';

    protected $description = 'Try to hold seats and print the result as JSON (testing utility)';

    public function handle(HoldService $holds, TenantContext $tenantContext): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $event = $tenantContext->runUnscoped(
            fn () => Event::with('tenant')->find($this->argument('event'))
        );

        if (! $event) {
            $this->line(json_encode(['ok' => false, 'code' => 'unknown_event']));

            return self::FAILURE;
        }

        $tenantContext->set($event->tenant);

        try {
            $hold = $holds->create(
                $event,
                explode(',', $this->argument('seats')),
                (string) $this->option('session'),
            );

            $this->line(json_encode(['ok' => true, 'token' => $hold->token]));

            return self::SUCCESS;
        } catch (ApiException $e) {
            $this->line(json_encode(['ok' => false, 'code' => $e->errorCode()]));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // An unexpected failure must be visible as such, not counted as a polite rejection.
            $this->line(json_encode(['ok' => false, 'code' => 'unexpected', 'message' => $e->getMessage()]));

            return self::SUCCESS;
        }
    }
}
