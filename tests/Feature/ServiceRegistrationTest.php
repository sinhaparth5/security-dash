<?php

use App\Models\AuditEvent;
use App\Models\Dependency;
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

test('deleting a service needs its name typed and takes its dependencies and tokens with it', function () {
    $service = Service::factory()->create(['name' => 'billing-api', 'environment' => 'production']);
    $dependency = Dependency::factory()->for($service)->create();
    $token = $service->createToken('heartbeat');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('services.show', $service))
        ->delete(route('services.destroy', $service), ['confirmation' => 'billing'])
        ->assertSessionHasErrors('confirmation');

    expect($service->fresh())->not->toBeNull();

    $this->actingAs($user)
        ->from(route('services.show', $service))
        ->delete(route('services.destroy', $service), ['confirmation' => 'billing-api'])
        ->assertRedirect(route('dashboard'));

    expect($service->fresh())->toBeNull()
        ->and($dependency->fresh())->toBeNull()
        ->and($token->accessToken->fresh())->toBeNull()
        ->and(AuditEvent::where('action', 'service.deleted')->sole()->details)->toBe(['name' => 'billing-api', 'environment' => 'production']);
});

test('deleting a service from the repositories list stays on the list', function () {
    $service = Service::factory()->create();

    $this->actingAs(User::factory()->create())
        ->from(route('repositories.index'))
        ->delete(route('services.destroy', $service), ['confirmation' => $service->name])
        ->assertRedirect(route('repositories.index'));
});

test('guests cannot delete services', function () {
    $service = Service::factory()->create();

    $this->delete(route('services.destroy', $service), ['confirmation' => $service->name])
        ->assertRedirect(route('login'));

    expect($service->fresh())->not->toBeNull();
});
