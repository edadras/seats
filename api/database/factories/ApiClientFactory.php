<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ApiClientFactory extends Factory
{
    protected $model = \App\Models\ApiClient::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->domainName(),
            'site_url' => 'https://'.$this->faker->domainName(),
            'allowed_origins' => [],
            'status' => 'active',
        ];
    }
}
