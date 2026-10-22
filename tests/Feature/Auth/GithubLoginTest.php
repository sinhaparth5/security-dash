<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GithubUser;

beforeEach(function () {
    config(['services.github.allowed_logins' => ['sinhaparth5']]);
});

test('guests see the GitHub login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/Login'));
});

test('login redirects to GitHub with this app as the callback and sign-up disabled', function () {
    config(['services.github.client_id' => 'test-client-id']);

    $location = $this->get(route('auth.github'))->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://github.com/login/oauth/authorize?');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray([
        'client_id' => 'test-client-id',
        'redirect_uri' => route('auth.github.callback'),
        'allow_signup' => 'false',
    ]);
});

test('the allow-listed GitHub account is signed in and gets a user on first login', function () {
    Socialite::fake('github', GithubUser::fake(['id' => '42', 'nickname' => 'SinhaParth5', 'name' => 'Parth Sinha', 'email' => 'parth@example.com']));

    $this->get(route('auth.github.callback'))->assertRedirect(route('dashboard'));

    $user = User::sole();
    expect($user->only('github_id', 'name', 'email'))->toBe(['github_id' => 42, 'name' => 'Parth Sinha', 'email' => 'parth@example.com']);
    $this->assertAuthenticatedAs($user);
});

test('a returning user keeps the name and alert email they set in settings', function () {
    $user = User::factory()->create(['github_id' => 42, 'name' => 'Custom Name', 'email' => 'alerts@example.com']);
    Socialite::fake('github', GithubUser::fake(['id' => '42', 'nickname' => 'sinhaparth5', 'email' => 'parth@example.com']));

    $this->get(route('auth.github.callback'))->assertRedirect(route('dashboard'));

    expect($user->fresh()->only('name', 'email'))->toBe(['name' => 'Custom Name', 'email' => 'alerts@example.com']);
    $this->assertAuthenticatedAs($user);
});

test('any other GitHub account is refused and no user is created', function () {
    Socialite::fake('github', GithubUser::fake(['id' => '7', 'nickname' => 'someone-else']));

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['github' => 'This GitHub account is not allowed to use the dashboard.']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a callback whose state does not match this session is refused', function () {
    $this->withSession(['state' => 'expected-state'])
        ->get(route('auth.github.callback', ['state' => 'forged-state', 'code' => 'abc']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('github');

    $this->assertGuest();
});

test('cancelling on GitHub returns to the login page with a message', function () {
    $this->get(route('auth.github.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['github' => 'GitHub sign-in was cancelled.']);
});

test('password, registration and passkey endpoints do not exist', function (string $uri, int $status) {
    $this->post($uri)->assertStatus($status);
})->with([
    'password login' => ['/login', 405],
    'registration' => ['/register', 404],
    'password reset' => ['/forgot-password', 404],
    'two-factor challenge' => ['/two-factor-challenge', 404],
    'passkey login' => ['/passkeys/login', 404],
]);

test('users can log out', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});
