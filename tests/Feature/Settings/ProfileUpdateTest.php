<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
});

test('profile update rejects an email address another user already has', function () {
    $other = User::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('profile.update'), ['name' => 'Test User', 'email' => $other->email])
        ->assertSessionHasErrors('email');
});

test('guests cannot open profile settings', function () {
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});
