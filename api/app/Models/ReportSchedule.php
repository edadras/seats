<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved report, a cadence, and the people it goes to.
 *
 * Never a result. The report is re-run at the moment it is sent, so a schedule cannot turn into a
 * cache of numbers that have since been corrected — which is the rule the whole report engine is
 * built on (ADR-0006 §2), and not one a timer gets to break.
 */
class ReportSchedule extends Model
{
    use BelongsToTenant, HasUuids;

    public const CADENCES = ['daily', 'weekly', 'monthly'];

    protected $fillable = [
        'tenant_id', 'report_id', 'name', 'cadence', 'hour', 'weekday', 'day_of_month',
        'timezone', 'locale', 'recipients', 'include_link', 'paused',
        'next_run_at', 'last_sent_at', 'last_rows', 'last_error', 'created_by',
    ];

    protected $casts = [
        'recipients' => 'array',
        'include_link' => 'boolean',
        'paused' => 'boolean',
        'hour' => 'integer',
        'weekday' => 'integer',
        'day_of_month' => 'integer',
        'last_rows' => 'integer',
        'next_run_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    public function report()
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * What to call it in a subject line: the schedule's own name, or the report's.
     *
     * `label` rather than `title` because Eloquent reads a bare `title()` as an attribute accessor
     * and insists it return an Attribute — a method name that happens to look like a column is a
     * 500 waiting for whoever adds the column.
     */
    public function label(): string
    {
        return (string) ($this->name ?: $this->report?->name ?: '');
    }
}
