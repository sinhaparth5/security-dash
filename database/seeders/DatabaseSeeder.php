<?php

namespace Database\Seeders;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $billingApi = Service::factory()->create([
            'name' => 'billing-api',
            'environment' => 'production',
            'status' => ServiceStatus::Green,
        ]);
        Dependency::factory()->for($billingApi)->create([
            'ecosystem' => Ecosystem::Runtime, 'name' => 'go', 'version' => '1.25.3', 'latest_version' => '1.25.3',
        ]);

        $webFrontend = Service::factory()->create([
            'name' => 'web-frontend',
            'environment' => 'production',
            'status' => ServiceStatus::Yellow,
        ]);
        Dependency::factory()->for($webFrontend)->outdated()->create([
            'ecosystem' => Ecosystem::Runtime, 'name' => 'node', 'version' => '22.11.0', 'latest_version' => '24.4.0',
        ]);

        $authGateway = Service::factory()->create([
            'name' => 'auth-gateway',
            'environment' => 'staging',
            'status' => ServiceStatus::Red,
        ]);
        $vulnerableDependency = Dependency::factory()->for($authGateway)->vulnerable()->create([
            'ecosystem' => Ecosystem::Go, 'name' => 'golang.org/x/net', 'version' => '0.20.0', 'latest_version' => '0.40.0',
        ]);
        Advisory::factory()->for($vulnerableDependency)->create();

        Service::factory()->neverPinged()->create([
            'name' => 'report-worker',
            'environment' => 'staging',
        ]);
    }
}
