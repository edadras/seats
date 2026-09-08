<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckinOperator extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'email', 'status'];
}
