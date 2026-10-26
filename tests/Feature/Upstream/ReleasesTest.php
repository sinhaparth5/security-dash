<?php

use App\Enums\Ecosystem;
use App\Models\Dependency;
use App\Upstream\Releases;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    Http::fake([
        'go.dev/dl/*' => Http::response([
            ['version' => 'go1.27.3', 'stable' => true],
            ['version' => 'go1.26.10', 'stable' => true],
            ['version' => 'go1.28rc1', 'stable' => false],
        ]),
        'nodejs.org/dist/index.json' => Http::response([
            ['version' => 'v26.12.1', 'security' => true],
            ['version' => 'v24.9.0', 'security' => false],
            ['version' => 'v24.8.1', 'security' => true],
            ['version' => 'v24.8.0', 'security' => false],
            ['version' => 'v24.7.0', 'security' => true],
        ]),
    ]);
});

test('latest compares runtimes within their release line', function (string $runtime, string $version, string $expected) {
    $dependency = Dependency::factory()->make(['ecosystem' => Ecosystem::Runtime, 'name' => $runtime, 'version' => $version]);

    expect(app(Releases::class)->latest($dependency))->toBe($expected);
})->with([
    'go on a supported minor' => ['go', 'go1.26.2', '1.26.10'],
    'go on an unsupported minor' => ['go', '1.24.5', '1.27.3'],
    'node on an older major' => ['node', 'v24.7.0', '24.9.0'],
    'node on an unlisted major' => ['node', '20.1.0', '26.12.1'],
]);

test('latest reads npm and Go module registries with their path escaping', function () {
    Http::fake([
        'registry.npmjs.org/@types%2Fnode/latest' => Http::response(['version' => '26.0.1']),
        'proxy.golang.org/github.com/!burnt!sushi/toml/@latest' => Http::response(['Version' => 'v1.5.0']),
    ]);

    $npm = Dependency::factory()->make(['ecosystem' => Ecosystem::Npm, 'name' => '@types/node']);
    $goModule = Dependency::factory()->make(['ecosystem' => Ecosystem::Go, 'name' => 'github.com/BurntSushi/toml']);

    expect(app(Releases::class)->latest($npm))->toBe('26.0.1');
    expect(app(Releases::class)->latest($goModule))->toBe('1.5.0');
});

test('latest returns null for a package the registry does not know', function () {
    Http::fake(['registry.npmjs.org/*' => Http::response(['error' => 'Not found'], 404)]);

    $private = Dependency::factory()->make(['ecosystem' => Ecosystem::Npm, 'name' => '@acme/internal']);

    expect(app(Releases::class)->latest($private))->toBeNull();
});

test('latest caches upstream release lists', function () {
    $dependency = Dependency::factory()->make(['ecosystem' => Ecosystem::Runtime, 'name' => 'go', 'version' => '1.26.2']);

    app(Releases::class)->latest($dependency);
    app(Releases::class)->latest($dependency);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://go.dev/dl/'));
});

test('nodeSecurityAdvisory flags the nearest newer security release in the same major', function () {
    $dependency = Dependency::factory()->make(['ecosystem' => Ecosystem::Runtime, 'name' => 'node', 'version' => 'v24.7.0']);

    expect(app(Releases::class)->nodeSecurityAdvisory($dependency))->toBe([
        'osv_id' => 'NODE-24.8.1',
        'aliases' => [],
        'severity' => null,
        'summary' => 'Node.js 24.8.1 is a security release; this runtime is older.',
        'fixed_version' => '24.8.1',
    ]);
});

test('nodeSecurityAdvisory returns null once the runtime has every security release in its line', function () {
    $dependency = Dependency::factory()->make(['ecosystem' => Ecosystem::Runtime, 'name' => 'node', 'version' => '24.8.1']);

    expect(app(Releases::class)->nodeSecurityAdvisory($dependency))->toBeNull();
});

test('prefetch caches answers, including a package the registry does not have, so latest asks no more', function () {
    $express = Dependency::factory()->make(['ecosystem' => Ecosystem::Npm, 'name' => 'express', 'version' => '4.17.0']);
    $private = Dependency::factory()->make(['ecosystem' => Ecosystem::Npm, 'name' => '@acme/internal', 'version' => '1.0.0']);
    Http::fake([
        'registry.npmjs.org/express/latest' => Http::response(['version' => '4.21.2']),
        'registry.npmjs.org/*' => Http::response(null, 404),
    ]);
    $releases = app(Releases::class);

    $releases->prefetch([$express, $private]);
    $sent = count(Http::recorded());

    expect($releases->latest($express))->toBe('4.21.2');
    expect($releases->latest($private))->toBeNull();
    expect(Http::recorded())->toHaveCount($sent);
});

test('a lookup that failed during prefetch still fails grading instead of reading as no newer version', function () {
    $express = Dependency::factory()->make(['ecosystem' => Ecosystem::Npm, 'name' => 'express', 'version' => '4.17.0']);
    Http::fake(['registry.npmjs.org/*' => Http::response('', 503)]);
    $releases = app(Releases::class);

    $releases->prefetch([$express]);

    $releases->latest($express);
})->throws(RequestException::class);
