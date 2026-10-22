<?php

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Jobs\EvaluateService;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ServiceTurnedRed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * @param  list<list<array{id: string, modified: string}>>  $vulnsPerQuery
 */
function fakeUpstream(array $vulnsPerQuery, string $npmLatest = '4.17.21'): void
{
    Http::fake([
        'api.osv.dev/v1/querybatch' => Http::response([
            'results' => array_map(fn (array $vulns) => ['vulns' => $vulns], $vulnsPerQuery),
        ]),
        'api.osv.dev/v1/vulns/*' => Http::response([
            'id' => 'GHSA-29mw-wpgm-hmr9',
            'summary' => 'ReDoS in lodash',
            'aliases' => ['CVE-2020-28500'],
            'database_specific' => ['severity' => 'MODERATE'],
            'affected' => [['package' => ['name' => 'lodash', 'ecosystem' => 'npm'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '4.17.21']]]]]],
        ]),
        'registry.npmjs.org/*' => Http::response(['version' => $npmLatest]),
    ]);
}

test('a dependency with an advisory turns red and stores the advisory', function () {
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($dependency->service);

    expect($dependency->fresh()->status)->toBe(ServiceStatus::Red);
    expect($dependency->service->fresh()->status)->toBe(ServiceStatus::Red);
    $this->assertDatabaseHas('advisories', [
        'dependency_id' => $dependency->id, 'osv_id' => 'GHSA-29mw-wpgm-hmr9', 'severity' => 'MODERATE', 'fixed_version' => '4.17.21',
    ]);
});

test('an outdated dependency without advisories turns yellow and records the latest version', function () {
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.21']);
    fakeUpstream([[]], npmLatest: '4.18.1');

    EvaluateService::dispatchSync($dependency->service);

    $dependency->refresh();
    expect($dependency->status)->toBe(ServiceStatus::Yellow);
    expect($dependency->latest_version)->toBe('4.18.1');
    expect($dependency->service->status)->toBe(ServiceStatus::Yellow);
});

test('a current dependency without advisories turns green', function () {
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.21']);
    fakeUpstream([[]]);

    EvaluateService::dispatchSync($dependency->service);

    expect($dependency->fresh()->status)->toBe(ServiceStatus::Green);
    expect($dependency->service->fresh()->status)->toBe(ServiceStatus::Green);
});

test('advisories fixed since the last evaluation are removed', function () {
    $dependency = Dependency::factory()->vulnerable()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.21']);
    $resolved = Advisory::factory()->for($dependency)->create();
    fakeUpstream([[]]);

    EvaluateService::dispatchSync($dependency->service);

    $this->assertModelMissing($resolved);
    expect($dependency->fresh()->status)->toBe(ServiceStatus::Green);
});

test('a dependency no source can evaluate stays unknown and does not mask the service status', function () {
    $service = Service::factory()->create();
    $image = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Image, 'name' => 'alpine', 'version' => '3.20']);
    Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.21']);
    fakeUpstream([[]]);

    EvaluateService::dispatchSync($service);

    expect($image->fresh()->status)->toBe(ServiceStatus::Unknown);
    expect($service->fresh()->status)->toBe(ServiceStatus::Green);
});

test('a service with nothing evaluable is unknown', function () {
    $service = Service::factory()->create(['status' => ServiceStatus::Green]);

    EvaluateService::dispatchSync($service);

    expect($service->fresh()->status)->toBe(ServiceStatus::Unknown);
});

test('an OSV outage fails the job and leaves the previous grades in place', function () {
    $dependency = Dependency::factory()->vulnerable()->create(['ecosystem' => Ecosystem::Npm]);
    $dependency->service->update(['status' => ServiceStatus::Red]);
    Http::fake(['api.osv.dev/*' => Http::response('', 503)]);

    expect(fn () => EvaluateService::dispatchSync($dependency->service))->toThrow(RequestException::class);

    expect($dependency->fresh()->status)->toBe(ServiceStatus::Red);
    expect($dependency->service->fresh()->status)->toBe(ServiceStatus::Red);
});

test('the hourly schedule queues an evaluation for every service', function () {
    Queue::fake();
    $services = Service::factory()->count(2)->create();

    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'evaluate-services');
    expect($event->expression)->toBe('0 * * * *');
    $event->run(app());

    Queue::assertPushed(EvaluateService::class, 2);
    $services->each(fn (Service $service) => Queue::assertPushed(EvaluateService::class, fn (EvaluateService $job) => $job->service->is($service)));
});

test('a service turning red mails every verified user once', function () {
    Notification::fake();
    $verified = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    $dependency->service->update(['status' => ServiceStatus::Green]);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($dependency->service);

    Notification::assertSentTo($verified, ServiceTurnedRed::class, fn (ServiceTurnedRed $notification) => $notification->service->is($dependency->service));
    Notification::assertNotSentTo($unverified, ServiceTurnedRed::class);
});

test('a service that was already red does not mail again', function () {
    Notification::fake();
    User::factory()->create();
    $dependency = Dependency::factory()->vulnerable()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    $dependency->service->update(['status' => ServiceStatus::Red]);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($dependency->service);

    Notification::assertNothingSent();
});
