<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CheckinDeviceFactory extends Factory
{
    protected $model = \App\Models\CheckinDevice::class;

    public function definition(): array
    {
        return [
            'name' => 'Door '.$this->faker->randomLetter(),
            'status' => 'active',
            'paired_at' => now(),
        ];
    }
}
