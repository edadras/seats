<?php

namespace App\Domain\Reports;

use App\Exceptions\ApiException;
use App\Modules\Contracts\ReportSource;
use Illuminate\Support\Facades\DB;

/**
 * Turn a definition into rows.
 *
 * The definition names dimensions, measures and filters by key. Every key is looked up in the
 * source's own declaration and replaced by the expression written there; a key that is not
 * declared is a validation error before a query is built (ADR-0006 §1). Nothing a caller sends is
 * ever used as an identifier, an operator, or a direction.
 *
 * Two hard limits are part of the contract (§6): a statement timeout and a row cap. A report that
 * exceeds either gets a clear refusal naming the limit rather than a spinner and a database under
 * load. The cap is applied as "ask for one more than we will show" — that is how the answer knows
 * it is incomplete rather than guessing.
 */
class ReportRunner
{
    public const MAX_ROWS = 1000;

    public const MAX_EXPORT_ROWS = 50000;

    public const TIMEOUT_SECONDS = 8;

    /** Aggregates, spelled here rather than chosen by the caller. */
    private const AGGREGATES = [
        'count' => 'count(%s)',
        'count_distinct' => 'count(distinct %s)',
        'sum' => 'coalesce(sum(%s), 0)',
        'avg' => 'avg(%s)',
        'min' => 'min(%s)',
        'max' => 'max(%s)',
    ];

    /**
     * @param  array{dimensions?:list<string>, measures?:list<string>, filters?:array<string,mixed>,
     *              sort?:array{key:string,direction:string}, limit?:int}  $definition
     */
    public function run(ReportSource $source, array $definition, int $cap = self::MAX_ROWS): array
    {
        $plan = $this->plan($source, $definition, $cap);
        $query = $source->query();
        $selects = [];

        foreach ($plan['dimensions'] as $alias => $field) {
            $selects[] = DB::raw($field['expression'].' as '.$alias);
            $query->groupBy(DB::raw($field['expression']));
        }

        foreach ($plan['measures'] as $alias => $field) {
            $selects[] = DB::raw(
                sprintf(self::AGGREGATES[$field['aggregate']], $field['expression']).' as '.$alias
            );
        }

        $query->select($selects);

        foreach ($plan['filters'] as $filter) {
            $this->applyFilter($query, $filter);
        }

        if ($plan['sort']) {
            $query->orderByRaw($plan['sort']['expression'].' '.$plan['sort']['direction']);
        }

        // One more than we will show, so "there is more" is a fact rather than a coincidence.
        $query->limit($plan['limit'] + 1);

        $rows = $this->withTimeout(fn () => $query->get()->map(
            fn ($row) => (array) $row->getAttributes()
        )->all());

        $truncated = count($rows) > $plan['limit'];

        return [
            'columns' => $plan['columns'],
            'rows' => $this->cast(array_slice($rows, 0, $plan['limit']), $plan),
            'truncated' => $truncated,
            'limit' => $plan['limit'],
            // Which column the answer is actually in order of. The screen draws an arrow on it,
            // and without this it would have to reimplement the default above to know where.
            'sort' => $plan['sort']
                ? ['alias' => $plan['sort']['expression'], 'direction' => $plan['sort']['direction']]
                : null,
        ];
    }

    /**
     * Validate a definition against the source and resolve every key to what it means.
     *
     * Public because saving a report validates it here too: a definition that cannot run is not
     * worth storing, and finding out at save time is kinder than finding out on a screen a week
     * later.
     */
    public function plan(ReportSource $source, array $definition, int $cap = self::MAX_ROWS): array
    {
        $declared = [
            'dimensions' => $source->dimensions(),
            'measures' => $source->measures(),
            'filters' => $source->filters(),
        ];

        $dimensions = [];
        $measures = [];
        $columns = [];

        foreach (array_values((array) ($definition['dimensions'] ?? [])) as $index => $key) {
            if (! is_string($key) || ! isset($declared['dimensions'][$key])) {
                throw $this->unknown('dimension', $key);
            }

            $alias = 'd'.$index;
            $dimensions[$alias] = $declared['dimensions'][$key];
            $columns[] = [
                'alias' => $alias,
                'key' => $key,
                'kind' => 'dimension',
                'type' => $declared['dimensions'][$key]['type'],
                'label' => __($declared['dimensions'][$key]['label_key']),
            ];
        }

        foreach (array_values((array) ($definition['measures'] ?? [])) as $index => $key) {
            if (! is_string($key) || ! isset($declared['measures'][$key])) {
                throw $this->unknown('measure', $key);
            }

            $alias = 'm'.$index;
            $measures[$alias] = $declared['measures'][$key];
            $columns[] = [
                'alias' => $alias,
                'key' => $key,
                'kind' => 'measure',
                'type' => 'number',
                'format' => $declared['measures'][$key]['format'] ?? 'number',
                'label' => __($declared['measures'][$key]['label_key']),
            ];
        }

        if ([] === $measures) {
            throw ApiException::unprocessable(
                'report_needs_a_measure',
                'A report has to count or add something up.'
            );
        }

        $filters = [];

        foreach ((array) ($definition['filters'] ?? []) as $key => $value) {
            if (! is_string($key) || ! isset($declared['filters'][$key])) {
                throw $this->unknown('filter', $key);
            }

            if (null === $value || '' === $value || [] === $value) {
                continue;
            }

            $filters[] = $declared['filters'][$key] + ['value' => $value];
        }

        return [
            'dimensions' => $dimensions,
            'measures' => $measures,
            'filters' => $filters,
            'columns' => $columns,
            'sort' => $this->sort($definition, $dimensions, $measures, $columns),
            'limit' => max(1, min((int) ($definition['limit'] ?? $cap), $cap)),
        ];
    }

    /* --------------------------------------------------------------------------- internals */

    private function sort(array $definition, array $dimensions, array $measures, array $columns): ?array
    {
        $sort = $definition['sort'] ?? null;

        if (! is_array($sort) || ! isset($sort['key']) || ! is_string($sort['key'])) {
            // A measure descending is what somebody means by "the biggest first", and it is what
            // they want nine times out of ten.
            $first = array_key_first($measures);

            return $first ? ['expression' => $first, 'direction' => 'desc'] : null;
        }

        $aliases = array_keys($dimensions + $measures);
        $alias = in_array($sort['key'], $aliases, true) ? $sort['key'] : null;

        /*
         * A definition may name the field rather than its position.
         *
         * `m0` is the first measure, which is a fine thing for a screen to send about the report
         * currently on it and a poor thing to store: drag a column in front of it and `m0` quietly
         * means something else, so a saved report changes what it is sorted by without anybody
         * touching the sort. `revenue` still means revenue.
         */
        if (null === $alias) {
            foreach ($columns as $column) {
                if ($column['key'] === $sort['key']) {
                    $alias = $column['alias'];

                    break;
                }
            }
        }

        if (null === $alias) {
            throw $this->unknown('sort', $sort['key']);
        }

        // The direction is chosen from two words written here, never taken from the request.
        return [
            'expression' => $alias,
            'direction' => 'asc' === ($sort['direction'] ?? 'desc') ? 'asc' : 'desc',
        ];
    }

    private function applyFilter($query, array $filter): void
    {
        $expression = DB::raw($filter['expression']);
        $value = $filter['value'];

        switch ($filter['type']) {
            case 'date_range':
                if (! empty($value['from'])) {
                    $query->where($expression, '>=', $value['from']);
                }

                if (! empty($value['to'])) {
                    $query->where($expression, '<=', $value['to']);
                }

                break;

            case 'enum':
                $allowed = array_values(array_intersect((array) $value, $filter['options'] ?? []));

                if ([] === $allowed) {
                    throw ApiException::unprocessable('unknown_filter_value', 'That filter value is not one of the choices.');
                }

                $query->whereIn($expression, $allowed);

                break;

            case 'event':
            case 'uuid':
                $query->whereIn($expression, array_filter((array) $value, 'is_string'));

                break;

            case 'boolean':
                $query->where($expression, filter_var($value, FILTER_VALIDATE_BOOLEAN));

                break;

            default:
                $query->where($expression, (string) $value);
        }
    }

    /**
     * Cast what Postgres hands back.
     *
     * Aggregates come out as strings through PDO; a report that renders "1200.0000000000000000"
     * for an average is a report somebody has to explain.
     */
    private function cast(array $rows, array $plan): array
    {
        $numeric = [];

        foreach ($plan['columns'] as $column) {
            if ('measure' === $column['kind']) {
                $numeric[$column['alias']] = true;
            }
        }

        return array_map(function (array $row) use ($numeric) {
            foreach ($row as $alias => $value) {
                if (isset($numeric[$alias]) && null !== $value) {
                    $row[$alias] = (float) $value == (int) $value ? (int) $value : round((float) $value, 2);
                }
            }

            return $row;
        }, $rows);
    }

    /**
     * A statement timeout, so one heavy report cannot hold a connection open indefinitely.
     *
     * `SET LOCAL` needs a transaction to be local *to*, which is why this wraps one. The timeout
     * is a refusal with a number in it, not a spinner (§6).
     */
    private function withTimeout(callable $work): array
    {
        try {
            return DB::transaction(function () use ($work) {
                DB::statement("SET LOCAL statement_timeout = '".self::TIMEOUT_SECONDS."s'");

                return $work();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'statement timeout')
                || str_contains($e->getMessage(), 'canceling statement')) {
                throw ApiException::unprocessable(
                    'report_too_slow',
                    'That report took longer than '.self::TIMEOUT_SECONDS.' seconds. Narrow it with a filter, or export it.',
                    ['timeout_seconds' => self::TIMEOUT_SECONDS],
                    ['seconds' => self::TIMEOUT_SECONDS],
                );
            }

            throw $e;
        }
    }

    private function unknown(string $what, mixed $key): ApiException
    {
        return ApiException::unprocessable(
            'unknown_'.$what,
            'That is not a field this report can use.',
            ['field' => is_string($key) ? $key : null],
        );
    }
}
