<?php

use App\Jobs\ScanRepository;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    fakeGithubApp();
    Http::fake([
        'api.github.com/app/installations*' => Http::sequence()
            ->push([['id' => 7]])
            ->push(['token' => 'ghs_bot'], 201),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => [
            ['full_name' => 'acme/billing-api', 'private' => true, 'default_branch' => 'main', 'html_url' => 'https://github.com/acme/billing-api'],
        ]]),
    ]);
});

test('watching a repository the bot is installed on creates a service and queues its first scan', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('repositories.store'), ['repository' => 'acme/billing-api'])
        ->assertRedirect();

    $service = Service::sole();
    expect($service)->name->toBe('billing-api')->environment->toBe('main')->repository->toBe('acme/billing-api')->watch_repository->toBeTrue();
    Queue::assertPushed(ScanRepository::class, fn (ScanRepository $job) => $job->service->is($service) && $job->sha === null);
    $this->assertDatabaseHas('audit_events', ['action' => 'service.repository_watched']);
});

test('a repository the bot is not installed on cannot be watched', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('repositories.store'), ['repository' => 'someone/else'])
        ->assertSessionHasErrors('repository');

    expect(Service::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the repositories page lists the installed repositories and which are watched', function () {
    $service = Service::factory()->create(['repository' => 'Acme/Billing-API', 'watch_repository' => true]);

    $this->actingAs(User::factory()->create())
        ->get(route('repositories.index'))
        ->assertInertia(fn (Assert $page) => $page->component('repositories/Index')
            ->where('app.install_url', 'https://github.com/apps/patchwatch-bot/installations/new')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('repositories.0.service_id', $service->id)));
});
