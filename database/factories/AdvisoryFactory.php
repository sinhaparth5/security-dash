<?php

namespace Database\Factories;

use App\Models\Advisory;
use App\Models\Dependency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Advisory>
 */
class AdvisoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dependency_id' => Dependency::factory()->vulnerable(),
            'osv_id' => 'GHSA-'.fake()->unique()->bothify('????-????-????'),
            'aliases' => ['CVE-'.fake()->year().'-'.fake()->numberBetween(1000, 99999)],
            'severity' => fake()->randomElement(['LOW', 'MODERATE', 'HIGH', 'CRITICAL']),
            'summary' => fake()->sentence(),
            'fixed_version' => fake()->semver(),
        ];
    }
}
