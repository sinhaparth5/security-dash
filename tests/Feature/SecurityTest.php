<?php

use App\Models\AuditEvent;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GithubUser;

test('pages carry a nonce CSP and anti-framing headers, and the inline script uses the nonce', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $match);
    expect($match[1] ?? null)->not->toBeNull();
    expect($response->getContent())->toContain('nonce="'.$match[1].'"');
    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
});

test('HSTS is only sent over HTTPS', function () {
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('new tokens carry the pw_ prefix so secret scanning can spot them', function () {
    expect(Service::factory()->create()->createToken('heartbeat')->plainTextToken)->toMatch('/^\d+\|pw_[A-Za-z0-9]{48}$/');
});

test('an issued token can expire and stops working after', function () {
    $service = Service::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('services.tokens.store', $service), ['expires_in_days' => 30])
        ->assertRedirect();

    $token = session('inertia.flash_data')['serviceToken']['token'];
    expect($service->tokens()->sole()->expires_at->toDateString())->toBe(now()->addDays(30)->toDateString());

    Queue::fake();
    auth()->forgetGuards();
    $this->travel(31)->days();
    $this->withToken($token)->postJson(route('api.v1.heartbeat'), ['dependencies' => []])->assertUnauthorized();
});

test('an expiry outside the offered choices is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('services.tokens.store', Service::factory()->create()), ['expires_in_days' => 7])
        ->assertSessionHasErrors('expires_in_days');
});

test('the IP allowlist is saved, validated, and enforced on the API', function () {
    Queue::fake();
    $service = Service::factory()->create();
    $token = $service->createToken('heartbeat')->plainTextToken;
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('services.allowed-ips.update', $service), ['allowed_ips' => "10.0.0.0/8\n not-an-ip"])
        ->assertSessionHasErrors('allowed_ips.1');

    $this->actingAs($user)
        ->put(route('services.allowed-ips.update', $service), ['allowed_ips' => "10.0.0.0/8, 192.168.1.5\n2001:db8::/32"])
        ->assertRedirect();
    expect($service->fresh()->allowed_ips)->toBe(['10.0.0.0/8', '192.168.1.5', '2001:db8::/32']);

    auth()->forgetGuards();
    $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson(route('api.v1.heartbeat'), ['dependencies' => []])->assertForbidden();

    auth()->forgetGuards();
    $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
        ->postJson(route('api.v1.heartbeat'), ['dependencies' => []])->assertNoContent();

    $this->actingAs($user)->put(route('services.allowed-ips.update', $service), ['allowed_ips' => ''])->assertRedirect();
    expect($service->fresh()->allowed_ips)->toBeNull();
});

test('token, allowlist and sign-in actions land in the audit log', function () {
    config(['services.github.allowed_logins' => ['sinhaparth5']]);
    Socialite::fake('github', GithubUser::fake(['id' => '42', 'nickname' => 'sinhaparth5', 'name' => 'Parth', 'email' => 'p@example.com']));
    $this->get(route('auth.github.callback'));
    $user = User::sole();

    $service = Service::factory()->create();
    $this->post(route('services.tokens.store', $service));
    $this->delete(route('services.tokens.destroy', [$service, $service->tokens()->sole()->id]));

    expect(AuditEvent::orderBy('id')->pluck('action')->all())->toBe(['user.login', 'token.issued', 'token.revoked']);
    expect(AuditEvent::where('action', 'token.revoked')->sole()->only('user_id', 'service_id'))->toBe(['user_id' => $user->id, 'service_id' => $service->id]);

    $this->get(route('audit.index'))->assertInertia(fn (Assert $page) => $page
        ->component('audit/Index')
        ->where('events.data.0.action', 'token.revoked')
        ->where('events.data.0.user', 'Parth'));
});

test('a refused GitHub account is audited', function () {
    config(['services.github.allowed_logins' => ['sinhaparth5']]);
    Socialite::fake('github', GithubUser::fake(['id' => '7', 'nickname' => 'intruder']));

    $this->get(route('auth.github.callback'));

    expect(AuditEvent::sole()->only('action', 'details'))->toBe(['action' => 'user.login_denied', 'details' => ['github_login' => 'intruder']]);
});
