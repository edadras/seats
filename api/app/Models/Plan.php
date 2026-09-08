<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['key', 'name', 'price_amount', 'currency', 'interval', 'limits', 'is_active'];

    protected $casts = ['limits' => 'array', 'is_active' => 'boolean', 'price_amount' => 'integer'];

    /** Null means "no limit" for this metric. */
    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
