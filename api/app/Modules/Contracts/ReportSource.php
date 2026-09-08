<?php

namespace App\Modules\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * A dataset the report builder can query (ADR-0006 §1).
 *
 * A source *declares* what may be grouped, measured and filtered. The builder composes a query from
 * identifiers the source declared, never from user text — a field name that is not in the
 * declaration does not reach the database, it is a validation error before a query is built.
 *
 * That is the whole reason this interface exists rather than a SQL box. `seats` is a multi-tenant
 * database whose safety story is a global scope on an Eloquent model; a feature that accepts SQL
 * from a customer has thrown that away, and no amount of statement parsing puts it back.
 */
interface ReportSource
{
    public function key(): string;

    public function labelKey(): string;

    /** The permission a viewer needs. Money is separated from operations, on purpose. */
    public function permission(): string;

    /**
     * Fields that may be grouped by.
     *
     * @return array<string, array{label_key:string, type:string, expression?:string}>
     */
    public function dimensions(): array;

    /**
     * Fields that may be aggregated, with the aggregate spelled out here rather than chosen by the
     * caller: "sum of amount" is a meaningful measure, "sum of seat id" is not.
     *
     * @return array<string, array{label_key:string, aggregate:string, expression:string, format?:string}>
     */
    public function measures(): array;

    /**
     * Filters and their types, so the builder knows what kind of input to validate.
     *
     * @return array<string, array{label_key:string, type:string, options?:list<string>, expression?:string}>
     */
    public function filters(): array;

    /**
     * The base query, already scoped to the current tenant by the same global scope everything else
     * uses. The builder adds only what the declarations above permit.
     */
    public function query(): Builder;
}
