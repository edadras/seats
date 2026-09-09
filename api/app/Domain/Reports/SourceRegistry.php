<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Sources\CheckinsSource;
use App\Domain\Reports\Sources\EventsSource;
use App\Domain\Reports\Sources\OrdersSource;
use App\Domain\Reports\Sources\SeatsSoldSource;
use App\Domain\Reports\Sources\TicketsSource;
use App\Modules\Contracts\ReportSource;
use App\Modules\ModuleRegistry;

/**
 * Every dataset a report can be built from: ours, and whatever the enabled modules contribute.
 *
 * A module adding a source is how a payment module contributes settlement reporting without core
 * knowing what a settlement is (ADR-0004, ADR-0006 §1). A module's source is subject to exactly
 * the same rules as ours — it declares fields, and the builder uses the declaration.
 */
class SourceRegistry
{
    private const FIRST_PARTY = [
        OrdersSource::class,
        SeatsSoldSource::class,
        TicketsSource::class,
        CheckinsSource::class,
        EventsSource::class,
    ];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /** @return array<string, ReportSource> */
    public function all(): array
    {
        $sources = [];

        foreach (self::FIRST_PARTY as $class) {
            $source = new $class();
            $sources[$source->key()] = $source;
        }

        foreach ($this->modules->contributions('reports') as $source) {
            // A module that contributes something which is not a source is ignored rather than
            // fatal: one broken module must not take the reports screen down for everyone.
            if ($source instanceof ReportSource) {
                $sources[$source->key()] = $source;
            }
        }

        return $sources;
    }

    public function find(string $key): ?ReportSource
    {
        return $this->all()[$key] ?? null;
    }
}
