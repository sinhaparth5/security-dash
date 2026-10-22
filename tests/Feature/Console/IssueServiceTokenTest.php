<?php

use App\Models\Service;

test('service:token registers the service and issues a token that authenticates the heartbeat', function () {
    $this->artisan('service:token', ['name' => 'billing-api', 'environment' => 'production'])
        ->assertSuccessful();

    $service = Service::where(['name' => 'billing-api', 'environment' => 'production'])->sole();
    expect($service->tokens()->count())->toBe(1);
});

test('service:token reuses an existing service', function () {
    $service = Service::factory()->create(['name' => 'billing-api', 'environment' => 'production']);

    $this->artisan('service:token', ['name' => 'billing-api', 'environment' => 'production'])
        ->assertSuccessful();

    $this->assertDatabaseCount('services', 1);
    expect($service->tokens()->count())->toBe(1);
});
