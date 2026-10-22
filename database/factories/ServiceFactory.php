<?php

namespace Database\Factories;

use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'environment' => fake()->randomElement(['production', 'staging']),
            'status' => ServiceStatus::Unknown,
            'last_ping_at' => now(),
        ];
    }

    /**
     * Indicate that the service has never sent a heartbeat.
     */
    public function neverPinged(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_ping_at' => null,
        ]);
    }
}
