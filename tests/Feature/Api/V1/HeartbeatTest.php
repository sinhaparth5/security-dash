<?php

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Jobs\EvaluateService;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
});

test('heartbeat without a token returns 401', function () {
    $this->postJson(route('api.v1.heartbeat'), ['dependencies' => []])
        ->assertUnauthorized();
});

test('heartbeat from a logged-in dashboard user returns 403', function () {
    $this->actingAs(User::factory()->create(), 'web');

    $this->postJson(route('api.v1.heartbeat'), ['dependencies' => []])
        ->assertForbidden();
});

test('a service is rate limited per token', function () {
    Sanctum::actingAs(Service::factory()->create());

    foreach (range(1, 60) as $_) {
        $this->postJson(route('api.v1.heartbeat'), ['dependencies' => []])->assertNoContent();
    }

    $this->postJson(route('api.v1.heartbeat'), ['dependencies' => []])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});

test('heartbeat with an invalid payload returns 422', function () {
    Sanctum::actingAs(Service::factory()->create());

    $this->postJson(route('api.v1.heartbeat'), [
        'dependencies' => [['ecosystem' => 'cobol', 'name' => '', 'version' => '1.0.0']],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'dependencies.0.ecosystem' => 'The selected dependencies.0.ecosystem is invalid.',
            'dependencies.0.name' => 'The dependencies.0.name field is required.',
        ]);
});

test('heartbeat without a dependencies list returns 422', function () {
    Sanctum::actingAs(Service::factory()->create());

    $this->postJson(route('api.v1.heartbeat'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['dependencies' => 'The dependencies field must be present.']);
});

test('heartbeat records new dependencies and the ping time', function () {
    $this->freezeSecond();
    $service = Service::factory()->neverPinged()->create();
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.heartbeat'), [
        'dependencies' => [
            ['ecosystem' => 'runtime', 'name' => 'go', 'version' => '1.25.3'],
            ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.21'],
        ],
    ])->assertNoContent();

    expect($service->fresh()->last_ping_at->equalTo(now()))->toBeTrue();
    $this->assertDatabaseHas('dependencies', [
        'service_id' => $service->id, 'ecosystem' => 'runtime', 'name' => 'go', 'version' => '1.25.3', 'status' => 'unknown',
    ]);
    $this->assertDatabaseHas('dependencies', [
        'service_id' => $service->id, 'ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.21',
    ]);
});

test('heartbeat resets a dependency whose version changed and drops its advisories', function () {
    $dependency = Dependency::factory()->vulnerable()->create([
        'ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20', 'latest_version' => '4.17.21',
    ]);
    Advisory::factory()->for($dependency)->create();
    Sanctum::actingAs($dependency->service);

    $this->postJson(route('api.v1.heartbeat'), [
        'dependencies' => [['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.21']],
    ])->assertNoContent();

    $dependency->refresh();
    expect($dependency->version)->toBe('4.17.21');
    expect($dependency->status)->toBe(ServiceStatus::Unknown);
    expect($dependency->latest_version)->toBeNull();
    $this->assertDatabaseCount('advisories', 0);
});

test('heartbeat keeps the evaluation of a dependency whose version is unchanged', function () {
    $dependency = Dependency::factory()->vulnerable()->create([
        'ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20',
    ]);
    Advisory::factory()->for($dependency)->create();
    Sanctum::actingAs($dependency->service);

    $this->postJson(route('api.v1.heartbeat'), [
        'dependencies' => [['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.20']],
    ])->assertNoContent();

    expect($dependency->fresh()->status)->toBe(ServiceStatus::Red);
    $this->assertDatabaseCount('advisories', 1);
});

test('heartbeat removes dependencies missing from the manifest without touching other services', function () {
    $service = Service::factory()->create();
    $removed = Dependency::factory()->for($service)->create();
    $otherServicesDependency = Dependency::factory()->create();
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.heartbeat'), ['dependencies' => []])
        ->assertNoContent();

    $this->assertModelMissing($removed);
    $this->assertModelExists($otherServicesDependency);
});

test('heartbeat queues an evaluation of the reporting service', function () {
    $service = Service::factory()->create();
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.heartbeat'), ['dependencies' => []])
        ->assertNoContent();

    Queue::assertPushed(EvaluateService::class, fn (EvaluateService $job) => $job->service->is($service));
});

test('rejected heartbeat does not queue an evaluation', function () {
    Sanctum::actingAs(Service::factory()->create());

    $this->postJson(route('api.v1.heartbeat'), [])->assertUnprocessable();

    Queue::assertNothingPushed();
});

test('heartbeat keeps images reported by CI scans', function () {
    $service = Service::factory()->create();
    $image = Dependency::factory()->for($service)->vulnerable()->create(['ecosystem' => Ecosystem::Image]);
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.heartbeat'), ['dependencies' => []])
        ->assertNoContent();

    $this->assertModelExists($image);
});

test('heartbeat cannot report images', function () {
    Sanctum::actingAs(Service::factory()->create());

    $this->postJson(route('api.v1.heartbeat'), [
        'dependencies' => [['ecosystem' => 'image', 'name' => 'alpine', 'version' => '3.20']],
    ])->assertJsonValidationErrors('dependencies.0.ecosystem');
});

test('heartbeat records the dependency graph and where the code imports each package', function () {
    $this->freezeSecond();
    $service = Service::factory()->create();
    $kept = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash.merge', 'version' => '4.6.2']);
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.heartbeat'), [
        'requires' => ['lodash.merge' => '^4.6.0', 'astro' => '^7.4.2'],
        'dependencies' => [
            ['ecosystem' => 'npm', 'name' => 'astro', 'version' => '7.4.2', 'requires' => ['vite' => '^6.0.0', 'bad' => ['not-a-range']]],
            ['ecosystem' => 'npm', 'name' => 'lodash.merge', 'version' => '4.6.2', 'requires' => []],
            ['ecosystem' => 'npm', 'name' => 'vite', 'version' => '6.0.0'],
        ],
        'usage' => [
            ['name' => 'astro', 'bin' => true, 'sites' => [['file' => 'astro.config.mjs', 'line' => 1, 'kind' => 'tooling']]],
            ['name' => 'lodash.merge', 'sites' => []],
        ],
    ])->assertNoContent();

    expect($service->fresh())
        ->requires->toBe(['lodash.merge' => '^4.6.0', 'astro' => '^7.4.2'])
        ->usage_scanned_at->equalTo(now())->toBeTrue();
    expect($service->dependencies()->where('name', 'astro')->first())
        ->requires->toBe(['vite' => '^6.0.0'])
        ->usage->toBe(['sites' => [['file' => 'astro.config.mjs', 'line' => 1, 'kind' => 'tooling']], 'bin' => true]);
    expect($kept->fresh()->usage)->toBe(['sites' => [], 'bin' => false]);
    expect($service->dependencies()->where('name', 'vite')->first())->requires->toBeNull()->usage->toBeNull();
});

test('a heartbeat without a usage scan clears the previous one', function () {
    $service = Service::factory()->create(['requires' => ['astro' => '^7.4.2'], 'usage_scanned_at' => now()]);
    $astro = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'astro', 'version' => '7.4.2', 'usage' => ['sites' => [], 'bin' => true]]);
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.heartbeat'), [
        'dependencies' => [['ecosystem' => 'npm', 'name' => 'astro', 'version' => '7.4.2']],
    ])->assertNoContent();

    expect($service->fresh())->requires->toBeNull()->usage_scanned_at->toBeNull();
    expect($astro->fresh()->usage)->toBeNull();
});

test('heartbeat with a malformed usage scan returns 422', function () {
    Sanctum::actingAs(Service::factory()->create());

    $this->postJson(route('api.v1.heartbeat'), [
        'dependencies' => [],
        'usage' => [['name' => 'astro', 'sites' => [['file' => 'a.ts', 'line' => 0, 'kind' => 'runtime']]]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['usage.0.sites.0.line', 'usage.0.sites.0.kind']);
});

test('a go symbol scan is split between the modules and the standard library it belongs to', function () {
    $service = Service::factory()->create();
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.heartbeat'), [
        'dependencies' => [
            ['ecosystem' => 'runtime', 'name' => 'go', 'version' => 'go1.24.1'],
            ['ecosystem' => 'go', 'name' => 'golang.org/x/net', 'version' => 'v0.17.0'],
            ['ecosystem' => 'go', 'name' => 'golang.org/x/net/v2', 'version' => 'v2.0.0'],
            ['ecosystem' => 'go', 'name' => 'github.com/gin-gonic/gin', 'version' => 'v1.10.0'],
        ],
        'symbols' => [
            'net/http' => ['Server.Serve', 'ListenAndServe'],
            'golang.org/x/net/http2' => ['Framer.ReadFrame'],
            'golang.org/x/net/v2/idna' => ['ToASCII'],
            'example.com/me/internal/api' => ['Handler'],
        ],
    ])->assertNoContent();

    $linked = $service->dependencies()->pluck('linked_symbols', 'name');

    expect($linked['go'])->toBe(['net/http.Server.Serve', 'net/http.ListenAndServe'])
        ->and($linked['golang.org/x/net'])->toBe(['golang.org/x/net/http2.Framer.ReadFrame'])
        ->and($linked['golang.org/x/net/v2'])->toBe(['golang.org/x/net/v2/idna.ToASCII'])
        ->and($linked['github.com/gin-gonic/gin'])->toBe([]);
});

test('a heartbeat without a symbol scan leaves go modules unscanned', function () {
    $service = Service::factory()->create();
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.heartbeat'), ['dependencies' => [['ecosystem' => 'go', 'name' => 'golang.org/x/net', 'version' => 'v0.17.0']]])->assertNoContent();

    expect($service->dependencies()->value('linked_symbols'))->toBeNull();
});
