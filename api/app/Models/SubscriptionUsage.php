<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionUsage extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'subscription_usage';

    protected $fillable = ['tenant_id', 'metric', 'period_start', 'period_end', 'used'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'used' => 'integer'];
}
