<?php

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Jobs\EvaluateService;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\RiskAcceptance;
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
function fakeUpstream(array $vulnsPerQuery, string $npmLatest = '4.17.21', string $osvId = 'GHSA-29mw-wpgm-hmr9', array $kev = [], array $epss = []): void
{
    Http::fake([
        'www.cisa.gov/*' => Http::response(['vulnerabilities' => array_map(fn (string $cve) => ['cveID' => $cve], $kev)]),
        'api.first.org/*' => Http::response(['data' => array_map(fn (string $cve, float $score) => ['cve' => $cve, 'epss' => (string) $score], array_keys($epss), $epss)]),
        'api.osv.dev/v1/querybatch' => Http::response([
            'results' => array_map(fn (array $vulns) => ['vulns' => $vulns], $vulnsPerQuery),
        ]),
        'api.osv.dev/v1/vulns/*' => Http::response([
            'id' => $osvId,
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

test('an advisory with no fixed version turns the dependency yellow and mails no one', function () {
    Notification::fake();
    User::factory()->create();
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Go, 'name' => 'golang.org/x/crypto', 'version' => 'v0.58.0']);
    $dependency->service->update(['status' => ServiceStatus::Green]);
    Http::fake([
        'api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => [['id' => 'GO-2026-5932', 'modified' => '2026-10-01T00:00:00Z']]]]]),
        'api.osv.dev/v1/vulns/*' => Http::response([
            'id' => 'GO-2026-5932',
            'affected' => [['package' => ['name' => 'golang.org/x/crypto', 'ecosystem' => 'Go'], 'ranges' => [['events' => [['introduced' => '0']]]]]],
        ]),
        'proxy.golang.org/*' => Http::response(['Version' => 'v0.58.0']),
    ]);

    EvaluateService::dispatchSync($dependency->service);

    expect($dependency->fresh()->status)->toBe(ServiceStatus::Yellow);
    expect($dependency->service->fresh()->status)->toBe(ServiceStatus::Yellow);
    $this->assertDatabaseHas('advisories', ['dependency_id' => $dependency->id, 'osv_id' => 'GO-2026-5932', 'fixed_version' => null]);
    Notification::assertNothingSent();
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
    $image = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Image, 'name' => 'alpine', 'version' => '3.20', 'status' => ServiceStatus::Unknown]);
    Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.21']);
    fakeUpstream([[]]);

    EvaluateService::dispatchSync($service);

    expect($image->fresh()->status)->toBe(ServiceStatus::Unknown);
    expect($service->fresh()->status)->toBe(ServiceStatus::Green);
});

test('a scanned image keeps its advisories and its red grade turns the service red', function () {
    $service = Service::factory()->create(['status' => ServiceStatus::Green]);
    $image = Dependency::factory()->for($service)->vulnerable()->create(['ecosystem' => Ecosystem::Image, 'name' => 'alpine', 'version' => '3.20']);
    $finding = Advisory::factory()->for($image)->create();
    Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.21']);
    fakeUpstream([[]]);

    EvaluateService::dispatchSync($service);

    $this->assertModelExists($finding);
    expect($image->fresh()->status)->toBe(ServiceStatus::Red);
    expect($service->fresh()->status)->toBe(ServiceStatus::Red);
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

test('a service turning red mails every user once', function () {
    Notification::fake();
    $users = User::factory()->count(2)->create();
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    $dependency->service->update(['status' => ServiceStatus::Green]);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($dependency->service);

    Notification::assertSentTo($users, ServiceTurnedRed::class, fn (ServiceTurnedRed $notification) => $notification->service->is($dependency->service));
    Notification::assertCount(2);
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

test('advisories in CISA KEV are marked and get their EPSS score', function () {
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]], kev: ['CVE-2020-28500'], epss: ['CVE-2020-28500' => 0.42]);

    EvaluateService::dispatchSync($dependency->service);

    $advisory = $dependency->advisories()->sole();
    expect($advisory->known_exploited)->toBeTrue();
    expect($advisory->epss)->toBe(0.42);
});

test('a KEV or EPSS outage still grades the service, just without the badges', function () {
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    Http::fake(['www.cisa.gov/*' => Http::response('', 503), 'api.first.org/*' => Http::response('', 503)]);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($dependency->service);

    expect($dependency->fresh()->status)->toBe(ServiceStatus::Red);
    expect($dependency->advisories()->sole()->known_exploited)->toBeFalse();
});

test('an accepted advisory no longer turns its dependency red', function () {
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    $dependency->service->riskAcceptances()->create(['osv_id' => 'GHSA-29mw-wpgm-hmr9', 'reason' => 'Not reachable']);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]], npmLatest: '4.17.21');

    EvaluateService::dispatchSync($dependency->service);

    expect($dependency->fresh()->status)->toBe(ServiceStatus::Yellow);
    expect($dependency->advisories()->count())->toBe(1);
});

test('an expired acceptance counts again', function () {
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    RiskAcceptance::forceCreate(['service_id' => $dependency->service_id, 'osv_id' => 'GHSA-29mw-wpgm-hmr9', 'reason' => 'Until the fix ships', 'expires_at' => now()->subDay()]);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($dependency->service);

    expect($dependency->fresh()->status)->toBe(ServiceStatus::Red);
});

test('an image whose findings are all accepted turns green', function () {
    $service = Service::factory()->create();
    $image = Dependency::factory()->for($service)->vulnerable()->create(['ecosystem' => Ecosystem::Image, 'name' => 'alpine', 'version' => '3.20']);
    Advisory::factory()->for($image)->create(['osv_id' => 'CVE-2024-0001']);
    $service->riskAcceptances()->create(['osv_id' => 'CVE-2024-0001', 'reason' => 'Package unused']);
    fakeUpstream([]);

    EvaluateService::dispatchSync($service);

    expect($image->fresh()->status)->toBe(ServiceStatus::Green);
    expect($service->fresh()->status)->toBe(ServiceStatus::Green);
});

test('a runtime past end of life turns red and records its end-of-life date', function () {
    $runtime = Dependency::factory()->create(['ecosystem' => Ecosystem::Runtime, 'name' => 'node', 'version' => 'v18.20.4']);
    Http::fake([
        'nodejs.org/dist/index.json' => Http::response([['version' => 'v18.20.4', 'security' => false]]),
        'endoflife.date/api/v1/products/nodejs' => Http::response(['result' => ['releases' => [['name' => '18', 'eolFrom' => '2025-04-30'], ['name' => '24', 'eolFrom' => '2028-04-30']]]]),
    ]);
    fakeUpstream([]);

    EvaluateService::dispatchSync($runtime->service);

    $runtime->refresh();
    expect($runtime->status)->toBe(ServiceStatus::Red);
    expect($runtime->eol_at->toDateString())->toBe('2025-04-30');
    expect($runtime->advisories()->sole()->osv_id)->toBe('EOL-node-18');
});

test('each status change is recorded once', function () {
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.21']);
    fakeUpstream([[]]);

    EvaluateService::dispatchSync($dependency->service);
    EvaluateService::dispatchSync($dependency->service);

    $service = $dependency->service->fresh();
    expect($service->statusChanges()->pluck('status')->all())->toBe([ServiceStatus::Green]);
    expect($service->status_changed_at)->not->toBeNull();
    expect($service->evaluated_at)->not->toBeNull();
});

test('a malicious package mails everyone even when the service was already red', function () {
    Notification::fake();
    User::factory()->create();
    $dependency = Dependency::factory()->vulnerable()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    $dependency->service->update(['status' => ServiceStatus::Red]);
    fakeUpstream([[['id' => 'MAL-2026-7299', 'modified' => '2026-10-01T00:00:00Z']]], osvId: 'MAL-2026-7299');

    EvaluateService::dispatchSync($dependency->service);

    Notification::assertCount(1);
});

test('jev triages counted advisories when a typesafe key is set', function () {
    config(['services.typesafe.key' => 'ts-test']);
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    Http::fake(['api.typesafe.ai/*' => Http::response(['model' => 'jev-1.13', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'answers' => [
        'urgency_0' => ['type' => 'score', 'score' => 1.2, 'confidence' => 0.8, 'legend' => [], 'probabilities' => []],
        'action_0' => ['type' => 'choice', 'choice' => 'upgrade', 'confidence' => 0.9, 'probabilities' => []],
    ]])]);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($dependency->service);

    expect($dependency->advisories()->first()->triage)->toBe(['urgency' => 1.2, 'urgency_confidence' => 0.8, 'action' => 'upgrade', 'action_confidence' => 0.9]);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.typesafe.ai/v1/systemone'
        && $request->hasHeader('Authorization', 'Bearer ts-test')
        && $request['state']['advisories'][0]['package'] === 'lodash'
        && $request['questions']['action_0']['type'] === 'choice');
});

test('a typesafe outage leaves grading intact and triage empty', function () {
    config(['services.typesafe.key' => 'ts-test']);
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    Http::fake(['api.typesafe.ai/*' => Http::response('', 503)]);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($dependency->service);

    expect($dependency->fresh()->status)->toBe(ServiceStatus::Red);
    expect($dependency->advisories()->first()->triage)->toBeNull();
});

test('re-grading keeps when an advisory was first seen so its fix-by date does not reset', function () {
    $dependency = Dependency::factory()->vulnerable()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    Advisory::factory()->for($dependency)->create(['osv_id' => 'GHSA-29mw-wpgm-hmr9', 'first_seen_at' => now()->subDays(10)->startOfSecond()]);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($dependency->service);

    $advisory = $dependency->advisories()->sole();
    expect($advisory->first_seen_at->toDateString())->toBe(now()->subDays(10)->toDateString());
    expect($advisory->dueAt()->toDateString())->toBe(now()->addDays(20)->toDateString());
});

test('turning red posts the alert to the slack webhook with service-reported text escaped', function () {
    Notification::fake();
    config(['services.alerts.webhook_url' => 'https://hooks.slack.com/services/T/B/x']);
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    $dependency->service->update(['name' => 'billing', 'status' => ServiceStatus::Green]);
    $image = Dependency::factory()->for($dependency->service)->vulnerable()->create(['ecosystem' => Ecosystem::Image, 'name' => '<!channel>']);
    Advisory::factory()->for($image)->create();
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);

    EvaluateService::dispatchSync($dependency->service);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://hooks.slack.com/')
        && str_contains($request['text'], 'Security patch required: billing')
        && str_contains($request['text'], 'lodash 4.17.20')
        && str_contains($request['text'], '&lt;!channel&gt;')
        && ! str_contains($request['text'], '<!channel>'));
});

test('discord webhooks get the alert with mentions and masked links disabled', function () {
    Notification::fake();
    config(['services.alerts.webhook_url' => 'https://discord.com/api/webhooks/1/x']);
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '[4.17.20](https://evil.example)']);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);
    Http::fake(['discord.com/*' => Http::response([], 204)]);

    EvaluateService::dispatchSync($dependency->service);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://discord.com/')
        && str_contains($request['content'], 'lodash \\[4.17.20\\](https://evil.example)') && $request['allowed_mentions'] === ['parse' => []]);
});

test('evaluation refreshes the state of open upgrade prs', function () {
    config(['services.github.pr_token' => 'ghp-test']);
    $service = Service::factory()->create(['repository' => 'acme/billing']);
    $dependency = Dependency::factory()->for($service)->create([
        'ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.21',
        'pull_request_url' => 'https://github.com/acme/billing/pull/7', 'pull_request_state' => 'open',
    ]);
    fakeUpstream([[]]);
    Http::fake(['api.github.com/repos/acme/billing/pulls/7' => Http::response(['state' => 'closed', 'merged_at' => '2026-10-23T10:00:00Z'])]);

    EvaluateService::dispatchSync($service);

    expect($dependency->fresh()->pull_request_state)->toBe('merged');
});

test('services that opted in get an upgrade pr opened when a dependency turns red', function () {
    config(['services.github.pr_token' => 'ghp-test']);
    $service = Service::factory()->create(['repository' => 'acme/billing', 'auto_pull_requests' => true]);
    $dependency = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    Dependency::factory()->for($service)->vulnerable()->create(['ecosystem' => Ecosystem::Image, 'name' => 'alpine']);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);
    Http::fake([
        'api.github.com/repos/acme/billing' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/billing/git/ref/heads/main' => Http::response(['object' => ['sha' => 'abc']]),
        'api.github.com/repos/acme/billing/git/trees/abc*' => Http::response(['tree' => [['path' => 'package.json', 'type' => 'blob']]]),
        'api.github.com/repos/acme/billing/contents/package.json*' => Http::sequence()
            ->push(['sha' => 'file1', 'content' => base64_encode('{"dependencies":{"lodash":"^4.17.20"}}')])
            ->push(['content' => []]),
        'api.github.com/repos/acme/billing/git/refs' => Http::response([], 201),
        'api.github.com/repos/acme/billing/pulls' => Http::response(['html_url' => 'https://github.com/acme/billing/pull/9'], 201),
        'api.github.com/repos/acme/billing/pulls/9' => Http::response(['state' => 'open', 'merged_at' => null]),
    ]);

    EvaluateService::dispatchSync($service);
    EvaluateService::dispatchSync($service);

    expect($dependency->fresh())->pull_request_url->toBe('https://github.com/acme/billing/pull/9')->pull_request_state->toBe('open');
    expect(Http::recorded(fn ($request) => str_ends_with($request->url(), '/pulls'))->count())->toBe(1);
    $this->assertDatabaseHas('audit_events', ['action' => 'pull_request.opened', 'user_id' => null]);
});

test('a fixable advisory in a package the code never reaches warns instead of turning red', function (string $osvId, ServiceStatus $expected) {
    $service = Service::factory()->create(['requires' => ['lodash' => '^4.17.0'], 'usage_scanned_at' => now()]);
    $dependency = Dependency::factory()->for($service)->create([
        'ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20', 'requires' => [], 'usage' => ['sites' => [], 'bin' => false],
    ]);
    fakeUpstream([[['id' => $osvId, 'modified' => '2025-09-29T21:12:31Z']]], osvId: $osvId);

    EvaluateService::dispatchSync($service);

    expect($dependency->fresh()->status)->toBe($expected);
})->with([
    'a vulnerability' => ['GHSA-29mw-wpgm-hmr9', ServiceStatus::Yellow],
    'malware, whose install scripts run regardless' => ['MAL-2026-7299', ServiceStatus::Red],
]);

test('evaluation stores the upgrade plan for the fixable npm advisories', function () {
    $service = Service::factory()->create(['requires' => ['lodash' => '^4.17.0']]);
    Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20', 'requires' => []]);
    Http::fake(['registry.npmjs.org/lodash' => Http::response(['versions' => ['4.17.20' => [], '4.17.21' => []]])]);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($service);

    expect($service->fresh()->fix_plan)->toBe([[
        'action' => 'refresh', 'package' => 'lodash', 'from' => '4.17.20', 'to' => '4.17.21',
        'via' => ['lodash'], 'fixes' => ['GHSA-29mw-wpgm-hmr9'], 'breaking' => false,
    ]]);
});

test('a registry outage keeps the previous upgrade plan and still grades the service', function () {
    $service = Service::factory()->create(['requires' => ['lodash' => '^4.17.0'], 'fix_plan' => [['action' => 'refresh']]]);
    $dependency = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20', 'requires' => []]);
    Http::fake(['registry.npmjs.org/lodash' => Http::response(status: 503)]);
    fakeUpstream([[['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]]);

    EvaluateService::dispatchSync($service);

    expect($dependency->fresh()->status)->toBe(ServiceStatus::Red)
        ->and($service->fresh()->fix_plan)->toBe([['action' => 'refresh']]);
});
