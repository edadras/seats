<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PlanFactory extends Factory
{
    protected $model = \App\Models\Plan::class;

    public function definition(): array
    {
        return [
            'key' => 'plan-'.Str::lower(Str::random(6)),
            'name' => 'Standard',
            'price_amount' => 9900,
            'currency' => 'EUR',
            'interval' => 'month',
            'limits' => [
                'max_venues' => 10,
                'max_events' => 100,
                'max_seats_per_map' => 20000,
            ],
            'is_active' => true,
        ];
    }
}
