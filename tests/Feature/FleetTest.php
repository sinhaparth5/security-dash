<?php

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('the packages page groups each package by version with the services running it', function () {
    $billing = Service::factory()->create(['name' => 'billing']);
    $auth = Service::factory()->create(['name' => 'auth']);
    $search = Service::factory()->create(['name' => 'search']);
    Dependency::factory()->for($billing)->vulnerable()->create(['name' => 'lodash', 'version' => '4.17.20']);
    Dependency::factory()->for($auth)->create(['name' => 'lodash', 'version' => '4.17.21']);
    Dependency::factory()->for($search)->create(['name' => 'lodash', 'version' => '4.17.21']);
    Dependency::factory()->for($search)->create(['name' => 'express']);

    $this->actingAs(User::factory()->create())
        ->get(route('packages.index', ['q' => 'LODA']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('packages/Index')
            ->has('packages', 1)
            ->where('packages.0.name', 'lodash')
            ->where('packages.0.status', 'red')
            ->where('packages.0.services_count', 3)
            ->where('packages.0.versions.0.version', '4.17.21')
            ->where('packages.0.versions.0.services.0.name', 'auth')
            ->where('packages.0.versions.0.services.1.name', 'search')
            ->where('packages.0.versions.1.version', '4.17.20')
            ->where('packages.0.versions.1.status', 'red')
        );

    $this->actingAs(User::factory()->create())
        ->get(route('packages.index'))
        ->assertInertia(fn (Assert $page) => $page->has('packages', 2)->where('packages.0.name', 'lodash'));
});

test('the status badge is public by token, rotating it kills the old url, and rotation is audited', function () {
    $service = Service::factory()->create(['status' => ServiceStatus::Red]);
    $user = User::factory()->create();

    $this->get('/badge/nope.svg')->assertNotFound();

    $this->actingAs($user)->post(route('services.badge.rotate', $service))->assertRedirect();
    $first = $service->fresh()->badge_token;
    $this->actingAs($user)->post(route('services.badge.rotate', $service));

    auth()->logout();
    $this->get(route('badge.show', $first))->assertNotFound();
    $this->get(route('badge.show', $service->fresh()->badge_token))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertSee('patchwatch: patch required', false);
    $this->assertDatabaseCount('audit_events', 2);
});

test('the service page only exposes the badge url once one is created', function () {
    $service = Service::factory()->create(['badge_token' => 'abc123']);

    $this->actingAs(User::factory()->create())
        ->get(route('services.show', $service))
        ->assertInertia(fn (Assert $page) => $page->where('badgeUrl', route('badge.show', 'abc123')));
});

test('opting into automatic prs is saved with the repository and audited', function () {
    $service = Service::factory()->create();

    $this->actingAs(User::factory()->create())
        ->put(route('services.repository.update', $service), ['repository' => 'acme/billing', 'auto_pull_requests' => '1']);

    expect($service->fresh()->auto_pull_requests)->toBeTrue();
    $this->assertDatabaseHas('audit_events', ['action' => 'service.repository_updated']);
});

test('the weekly digest summarises red services, recoveries, overdue advisories and open prs', function () {
    $red = Service::factory()->create(['name' => 'billing', 'environment' => 'production', 'status' => ServiceStatus::Red]);
    $red->statusChanges()->create(['status' => ServiceStatus::Red]);
    $recovered = Service::factory()->create(['name' => 'auth', 'environment' => 'staging', 'status' => ServiceStatus::Green]);
    $recovered->statusChanges()->create(['status' => ServiceStatus::Green]);
    $dependency = Dependency::factory()->for($red)->vulnerable()->create(['pull_request_state' => 'open']);
    Advisory::factory()->for($dependency)->create(['severity' => 'CRITICAL', 'first_seen_at' => now()->subDays(3)]);

    $content = (string) (new WeeklyDigest)->toMail(User::factory()->make())->render();

    expect($content)
        ->toContain('1 service needs a security patch right now.')
        ->toContain('Turned Red this week: billing (production).')
        ->toContain('Back to Green: auth (staging).')
        ->toContain('billing (production): 1 overdue advisory.')
        ->toContain('1 upgrade PR opened by Patchwatch is still open.');
});

test('the weekly digest goes to every user on monday mornings', function () {
    Notification::fake();
    $users = User::factory()->count(2)->create();

    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'weekly-digest');
    expect($event->expression)->toBe('0 8 * * 1');
    $event->run(app());

    Notification::assertSentTo($users, WeeklyDigest::class);
});

test('npm names one typo away from a popular package are flagged', function (string $name, Ecosystem $ecosystem, ?string $lookalike) {
    expect(Dependency::factory()->make(['name' => $name, 'ecosystem' => $ecosystem])->lookalike())->toBe($lookalike);
})->with([
    'swapped letters' => ['lodahs', Ecosystem::Npm, 'lodash'],
    'same letters, not a swap' => ['hsadol', Ecosystem::Npm, null],
    'one letter off' => ['lodasg', Ecosystem::Npm, 'lodash'],
    'missing letter' => ['expres', Ecosystem::Npm, 'express'],
    'the real one' => ['express', Ecosystem::Npm, null],
    'too short to tell' => ['vuex', Ecosystem::Npm, null],
    'not npm' => ['expres', Ecosystem::Go, null],
]);

test('fix-by dates follow severity, with a day for malware and exploited advisories', function () {
    $seen = now()->subDay()->startOfSecond();
    $advisory = fn (array $attributes) => Advisory::factory()->make(['first_seen_at' => $seen, 'known_exploited' => false, ...$attributes]);

    expect($advisory(['severity' => 'CRITICAL'])->dueAt()->equalTo($seen->copy()->addDays(2)))->toBeTrue();
    expect($advisory(['severity' => 'low'])->dueAt()->equalTo($seen->copy()->addDays(90)))->toBeTrue();
    expect($advisory(['severity' => null])->dueAt()->equalTo($seen->copy()->addDays(30)))->toBeTrue();
    expect($advisory(['severity' => 'LOW', 'known_exploited' => true])->dueAt()->equalTo($seen->copy()->addDay()))->toBeTrue();
    expect($advisory(['severity' => 'LOW', 'osv_id' => 'MAL-2026-1'])->dueAt()->equalTo($seen->copy()->addDay()))->toBeTrue();
});
