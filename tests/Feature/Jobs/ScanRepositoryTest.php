<?php

use App\Enums\ServiceStatus;
use App\Jobs\ScanRepository;
use App\Models\Service;
use App\Upstream\RepositoryScanner;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    fakeGithubApp();
});

test('a watched repository is graded from the scanned commit and the grade is posted as its check', function () {
    $service = Service::factory()->create(['repository' => 'acme/billing', 'watch_repository' => true]);
    $this->mock(RepositoryScanner::class, fn ($scanner) => $scanner->shouldReceive('scan')->with('acme/billing', 'abc123')->andReturn([
        'dependencies' => ['npm|lodash' => ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.21', 'requires' => [], 'usage' => null]],
        'requires' => ['lodash' => '^4.17.0'],
        'usage_scanned' => true,
    ]));
    Http::fake([
        'api.github.com/repos/acme/billing/installation' => Http::response(['id' => 7]),
        'api.github.com/app/installations/7/access_tokens' => Http::response(['token' => 'ghs_bot'], 201),
        'api.github.com/repos/acme/billing/check-runs*' => Http::response(['id' => 501], 201),
        'api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => []]]]),
        'registry.npmjs.org/lodash/latest' => Http::response(['version' => '4.17.21']),
        'api.deps.dev/*' => Http::response(null, 404),
        'registry.npmjs.org/*' => Http::response(null, 404),
    ]);

    ScanRepository::dispatchSync($service, 'abc123');

    expect($service->fresh())->status->toBe(ServiceStatus::Green)->requires->toBe(['lodash' => '^4.17.0'])->usage_scanned_at->not->toBeNull();
    expect($service->dependencies()->pluck('version', 'name')->all())->toBe(['lodash' => '4.17.21']);
    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), 'check-runs/501')
        && $request['conclusion'] === 'success' && $request['details_url'] === route('services.show', $service));
});

test('a repository that cannot be read leaves the service alone and says why on the check', function () {
    $service = Service::factory()->create(['repository' => 'acme/billing', 'watch_repository' => true, 'status' => ServiceStatus::Yellow]);
    $this->mock(RepositoryScanner::class, fn ($scanner) => $scanner->shouldReceive('scan')->andThrow(new RuntimeException('Found no lockfile to read.')));
    Http::fake([
        'api.github.com/repos/acme/billing/installation' => Http::response(['id' => 7]),
        'api.github.com/app/installations/7/access_tokens' => Http::response(['token' => 'ghs_bot'], 201),
        'api.github.com/repos/acme/billing/check-runs*' => Http::response(['id' => 501], 201),
    ]);

    ScanRepository::dispatchSync($service, 'abc123');

    expect($service->fresh()->status)->toBe(ServiceStatus::Yellow);
    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['conclusion'] === 'neutral' && $request['output']['summary'] === 'Found no lockfile to read.');
});
