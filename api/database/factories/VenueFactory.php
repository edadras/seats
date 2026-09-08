<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class VenueFactory extends Factory
{
    protected $model = \App\Models\Venue::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' Hall',
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'country' => 'DE',
            'timezone' => 'Europe/Berlin',
            'metadata' => [],
        ];
    }
}
