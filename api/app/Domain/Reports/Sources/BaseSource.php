<?php

namespace App\Domain\Reports\Sources;

use App\Modules\Contracts\ReportSource;

/**
 * Shared shape for the first-party sources.
 *
 * Every declaration here is data: a label key, a type, and an SQL expression written *in this
 * file*. Nothing a caller sends is ever used as an identifier — the builder looks a name up in
 * these arrays and uses what it finds, or refuses (ADR-0006 §1). That is the whole reason there is
 * no query box: `seats` is a multi-tenant database whose safety story is a global scope, and a
 * feature that accepts SQL from a customer has thrown that away.
 */
abstract class BaseSource implements ReportSource
{
    public function labelKey(): string
    {
        return 'reports.sources.'.$this->key().'.name';
    }

    public function descriptionKey(): string
    {
        return 'reports.sources.'.$this->key().'.description';
    }

    public function dimensions(): array
    {
        return [];
    }

    public function measures(): array
    {
        return [];
    }

    public function filters(): array
    {
        return [];
    }

    /** A dimension in the shape the builder expects. */
    protected function dimension(string $key, string $expression, string $type = 'string'): array
    {
        return [
            'label_key' => 'reports.fields.'.$key,
            'type' => $type,
            'expression' => $expression,
        ];
    }

    protected function measure(
        string $key,
        string $aggregate,
        string $expression,
        string $format = 'number',
    ): array {
        return [
            'label_key' => 'reports.fields.'.$key,
            'aggregate' => $aggregate,
            'expression' => $expression,
            'format' => $format,
        ];
    }

    protected function filter(string $key, string $type, string $expression, array $options = []): array
    {
        return array_filter([
            'label_key' => 'reports.fields.'.$key,
            'type' => $type,
            'expression' => $expression,
            'options' => $options ?: null,
        ], fn ($value) => null !== $value);
    }
}
