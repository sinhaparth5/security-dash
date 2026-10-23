<?php

use App\Models\Service;
use App\Models\User;

test('guests cannot register services', function () {
    $this->post(route('services.store'), ['name' => 'billing-api', 'environment' => 'production'])
        ->assertRedirect(route('login'));

    expect(Service::count())->toBe(0);
});

test('registering a service flashes a token that authenticates heartbeats', function () {
    $this->actingAs(User::factory()->create())
        ->from(route('dashboard'))
        ->post(route('services.store'), ['name' => 'billing-api', 'environment' => 'production'])
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('serviceToken.name', 'billing-api')
        ->assertInertiaFlash('serviceToken.existing', false);

    $token = session('inertia.flash_data')['serviceToken']['token'];

    auth()->forgetGuards();

    $this->withToken($token)
        ->postJson(route('api.v1.heartbeat'), ['dependencies' => []])
        ->assertNoContent();
});

test('registering an existing service issues an additional token', function () {
    $service = Service::factory()->create(['name' => 'billing-api', 'environment' => 'production']);
    $service->createToken('heartbeat');

    $this->actingAs(User::factory()->create())
        ->post(route('services.store'), ['name' => 'billing-api', 'environment' => 'production'])
        ->assertInertiaFlash('serviceToken.existing', true);

    expect(Service::count())->toBe(1)
        ->and($service->tokens()->count())->toBe(2);
});

test('service names are validated', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('services.store'), ['name' => 'bad name!', 'environment' => ''])
        ->assertSessionHasErrors(['name', 'environment']);
});

test('revoking a token stops it authenticating', function () {
    $service = Service::factory()->create();
    $revoked = $service->createToken('heartbeat');
    $kept = $service->createToken('heartbeat');

    $this->actingAs(User::factory()->create())
        ->delete(route('services.tokens.destroy', [$service, $revoked->accessToken->id]))
        ->assertRedirect();

    auth()->forgetGuards();
    $this->withToken($revoked->plainTextToken)->postJson(route('api.v1.heartbeat'), ['dependencies' => []])->assertUnauthorized();

    auth()->forgetGuards();
    $this->withToken($kept->plainTextToken)->postJson(route('api.v1.heartbeat'), ['dependencies' => []])->assertNoContent();
});

test('a token can only be revoked through its own service', function () {
    $token = Service::factory()->create()->createToken('heartbeat');
    $other = Service::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('services.tokens.destroy', [$other, $token->accessToken->id]))
        ->assertNotFound();

    expect($token->accessToken->fresh())->not->toBeNull();
});

test('guests cannot revoke tokens', function () {
    $service = Service::factory()->create();
    $token = $service->createToken('heartbeat');

    $this->delete(route('services.tokens.destroy', [$service, $token->accessToken->id]))
        ->assertRedirect(route('login'));

    expect($token->accessToken->fresh())->not->toBeNull();
});
