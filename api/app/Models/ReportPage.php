<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A layout of report widgets — table, bar, line, stat — over saved reports (ADR-0006 §3). */
class ReportPage extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'slug', 'widgets', 'created_by'];

    protected $casts = ['widgets' => 'array'];
}
