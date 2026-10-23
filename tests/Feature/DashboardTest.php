<?php

use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard lists services worst status first with their dependencies and advisories', function () {
    Service::factory()->create(['name' => 'a-green', 'status' => ServiceStatus::Green]);
    Service::factory()->create(['name' => 'b-unknown', 'status' => ServiceStatus::Unknown]);
    Service::factory()->create(['name' => 'c-yellow', 'status' => ServiceStatus::Yellow]);
    $red = Service::factory()->create(['name' => 'd-red', 'status' => ServiceStatus::Red]);
    $dependency = Dependency::factory()->for($red)->vulnerable()->create(['name' => 'lodash', 'version' => '4.17.20']);
    Advisory::factory()->for($dependency)->create(['osv_id' => 'GHSA-29mw-wpgm-hmr9', 'fixed_version' => '4.17.21']);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('services.0.name', 'd-red')
            ->where('services.1.name', 'c-yellow')
            ->where('services.2.name', 'b-unknown')
            ->where('services.3.name', 'a-green')
            ->where('services.0.dependencies.0.name', 'lodash')
            ->where('services.0.dependencies.0.status', 'red')
            ->where('services.0.dependencies.0.advisories.0.osv_id', 'GHSA-29mw-wpgm-hmr9')
            ->where('services.0.dependencies.0.advisories.0.fixed_version', '4.17.21')
        );
});

test('dashboard flags services that have not reported recently as stale', function (?string $lastPing, bool $isStale) {
    $this->freezeSecond();
    Service::factory()->create(['last_ping_at' => $lastPing === null ? null : now()->sub($lastPing)]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('services.0.is_stale', $isStale));
})->with([
    'reported an hour ago' => ['1 hour', false],
    'reported two days ago' => ['2 days', true],
    'never reported' => [null, true],
]);

test('service page shows one service with its dependencies worst first', function () {
    $service = Service::factory()->create(['status' => ServiceStatus::Red]);
    Dependency::factory()->for($service)->create(['name' => 'a-current', 'status' => ServiceStatus::Green]);
    $vulnerable = Dependency::factory()->for($service)->vulnerable()->create(['name' => 'lodash']);
    Advisory::factory()->for($vulnerable)->create(['osv_id' => 'GHSA-29mw-wpgm-hmr9']);

    $this->actingAs(User::factory()->create())
        ->get(route('services.show', $service))
        ->assertInertia(fn (Assert $page) => $page
            ->component('services/Show')
            ->where('service.id', $service->id)
            ->where('service.dependencies.0.name', 'lodash')
            ->where('service.dependencies.0.advisories.0.osv_id', 'GHSA-29mw-wpgm-hmr9')
            ->where('service.dependencies.1.name', 'a-current')
        );
});

test('guests cannot open a service page', function () {
    $this->get(route('services.show', Service::factory()->create()))->assertRedirect(route('login'));
});
