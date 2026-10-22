<?php

namespace Database\Factories;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Models\Dependency;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dependency>
 */
class DependencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = fake()->semver();

        return [
            'service_id' => Service::factory(),
            'ecosystem' => Ecosystem::Npm,
            'name' => fake()->unique()->slug(2),
            'version' => $version,
            'latest_version' => $version,
            'status' => ServiceStatus::Green,
        ];
    }

    /**
     * Indicate that a newer upstream version exists.
     */
    public function outdated(): static
    {
        return $this->state(fn (array $attributes) => [
            'version' => '1.0.0',
            'latest_version' => '2.0.0',
            'status' => ServiceStatus::Yellow,
        ]);
    }

    /**
     * Indicate that the running version has a known advisory.
     */
    public function vulnerable(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServiceStatus::Red,
        ]);
    }
}
