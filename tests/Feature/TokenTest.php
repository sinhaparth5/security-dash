<?php

use App\Models\Service;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the tokens page lists services and shows nothing until one is selected', function () {
    $service = Service::factory()->create(['name' => 'billing-api']);
    $service->createToken('heartbeat');

    $this->actingAs(User::factory()->create())
        ->get(route('tokens.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('tokens/Index')
            ->where('services.0.name', 'billing-api')
            ->where('services.0.tokens_count', 1)
            ->where('selected', null));
});

test('selecting a service shows its tokens newest first', function () {
    $service = Service::factory()->create();
    $older = $service->createToken('heartbeat')->accessToken;
    $newer = $service->createToken('heartbeat')->accessToken;

    $this->actingAs(User::factory()->create())
        ->get(route('tokens.index', ['service' => $service->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.id', $service->id)
            ->where('selected.tokens.0.id', $newer->id)
            ->where('selected.tokens.1.id', $older->id));
});

test('selecting an unknown service is a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('tokens.index', ['service' => 999]))
        ->assertNotFound();
});

test('issuing a token adds one that authenticates and keeps the old ones', function () {
    $service = Service::factory()->create();
    $old = $service->createToken('heartbeat');

    $this->actingAs(User::factory()->create())
        ->post(route('services.tokens.store', $service))
        ->assertRedirect()
        ->assertInertiaFlash('serviceToken.name', $service->name);

    $issued = session('inertia.flash_data')['serviceToken']['token'];

    foreach ([$issued, $old->plainTextToken] as $token) {
        auth()->forgetGuards();
        $this->withToken($token)->postJson(route('api.v1.heartbeat'), ['dependencies' => []])->assertNoContent();
    }

    expect($service->tokens()->count())->toBe(2);
});

test('guests cannot see or issue tokens', function () {
    $service = Service::factory()->create();

    $this->get(route('tokens.index'))->assertRedirect(route('login'));
    $this->post(route('services.tokens.store', $service))->assertRedirect(route('login'));

    expect($service->tokens()->count())->toBe(0);
});
