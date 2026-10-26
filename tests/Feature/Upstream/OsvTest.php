<?php

use App\Enums\Ecosystem;
use App\Models\Dependency;
use App\Upstream\Osv;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('advisories maps OSV results to each dependency with the nearest fixed version', function () {
    $lodash = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    $clean = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'left-pad', 'version' => '1.3.0']);

    Http::fake([
        'api.osv.dev/v1/querybatch' => Http::response(['results' => [
            ['vulns' => [['id' => 'GHSA-29mw-wpgm-hmr9', 'modified' => '2025-09-29T21:12:31Z']]],
            [],
        ]]),
        'api.osv.dev/v1/vulns/GHSA-29mw-wpgm-hmr9' => Http::response([
            'id' => 'GHSA-29mw-wpgm-hmr9',
            'summary' => 'ReDoS in lodash',
            'aliases' => ['CVE-2020-28500'],
            'database_specific' => ['severity' => 'MODERATE'],
            'affected' => [
                ['package' => ['name' => 'lodash-es', 'ecosystem' => 'npm'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '4.17.25']]]]],
                ['package' => ['name' => 'lodash', 'ecosystem' => 'npm'], 'ranges' => [['events' => [
                    ['introduced' => '0'], ['fixed' => '3.10.2'], ['introduced' => '4.0.0'], ['fixed' => '4.17.22'], ['fixed' => '4.17.21'],
                ]]]],
            ],
        ]),
    ]);

    $advisories = app(Osv::class)->advisories([$lodash, $clean]);

    expect($advisories[$lodash->id])->toBe([[
        'osv_id' => 'GHSA-29mw-wpgm-hmr9',
        'aliases' => ['CVE-2020-28500'],
        'severity' => 'MODERATE',
        'summary' => 'ReDoS in lodash',
        'fixed_version' => '4.17.21',
        'affected_packages' => [],
        'affected_symbols' => [],
    ]]);
    expect($advisories[$clean->id])->toBe([]);
});

test('advisories without a fixed version keep the affected Go packages and functions', function () {
    $crypto = Dependency::factory()->create(['ecosystem' => Ecosystem::Go, 'name' => 'golang.org/x/crypto', 'version' => 'v0.58.0']);

    Http::fake([
        'api.osv.dev/v1/querybatch' => Http::response(['results' => [
            ['vulns' => [['id' => 'GO-2026-5932', 'modified' => '2026-10-01T00:00:00Z']]],
        ]]),
        'api.osv.dev/v1/vulns/GO-2026-5932' => Http::response([
            'id' => 'GO-2026-5932',
            'summary' => 'Unmaintained OpenPGP implementation',
            'affected' => [[
                'package' => ['name' => 'golang.org/x/crypto', 'ecosystem' => 'Go'],
                'ranges' => [['events' => [['introduced' => '0']]]],
                'ecosystem_specific' => ['imports' => [
                    ['path' => 'golang.org/x/crypto/openpgp', 'symbols' => ['ReadMessage', 'Entity.Serialize']],
                    ['path' => 'golang.org/x/crypto/openpgp/armor'],
                ]],
            ]],
        ]),
    ]);

    $advisory = app(Osv::class)->advisories([$crypto])[$crypto->id][0];

    expect($advisory['fixed_version'])->toBeNull();
    expect($advisory['affected_packages'])->toBe(['golang.org/x/crypto/openpgp', 'golang.org/x/crypto/openpgp/armor']);
    expect($advisory['affected_symbols'])->toBe(['golang.org/x/crypto/openpgp.ReadMessage', 'golang.org/x/crypto/openpgp.Entity.Serialize']);
});

test('advisories queries the Go runtime as stdlib and skips ecosystems OSV does not track', function () {
    $goRuntime = Dependency::factory()->create(['ecosystem' => Ecosystem::Runtime, 'name' => 'go', 'version' => 'go1.22.0']);
    $goModule = Dependency::factory()->create(['ecosystem' => Ecosystem::Go, 'name' => 'golang.org/x/net', 'version' => 'v0.20.0']);
    $nodeRuntime = Dependency::factory()->create(['ecosystem' => Ecosystem::Runtime, 'name' => 'node', 'version' => '22.1.0']);
    $image = Dependency::factory()->create(['ecosystem' => Ecosystem::Image, 'name' => 'alpine', 'version' => '3.20']);

    Http::fake(['api.osv.dev/v1/querybatch' => Http::response(['results' => [[], []]])]);

    $advisories = app(Osv::class)->advisories([$goRuntime, $goModule, $nodeRuntime, $image]);

    Http::assertSent(fn (Request $request) => $request['queries'] === [
        ['package' => ['name' => 'stdlib', 'ecosystem' => 'Go'], 'version' => '1.22.0'],
        ['package' => ['name' => 'golang.org/x/net', 'ecosystem' => 'Go'], 'version' => '0.20.0'],
    ]);
    expect(array_keys($advisories))->toBe([$goRuntime->id, $goModule->id]);
});

test('advisories throws when OSV is unavailable instead of reporting no advisories', function () {
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm]);

    Http::fake(['api.osv.dev/*' => Http::response('', 503)]);

    app(Osv::class)->advisories([$dependency]);
})->throws(RequestException::class);

test('advisories follows OSV page tokens so a paged result is not read as fewer advisories', function () {
    $stdlib = Dependency::factory()->create(['ecosystem' => Ecosystem::Runtime, 'name' => 'go', 'version' => 'go1.16.0']);
    $clean = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'left-pad', 'version' => '1.3.0']);
    $vuln = fn (string $id) => ['id' => $id, 'modified' => '2026-10-01T00:00:00Z'];

    Http::fake([
        'api.osv.dev/v1/querybatch' => Http::sequence()
            ->push(['results' => [['vulns' => [$vuln('GO-1')], 'next_page_token' => 'page-2'], []]])
            ->push(['results' => [['vulns' => [$vuln('GO-2')]]]]),
        'api.osv.dev/v1/vulns/*' => fn (Request $request) => Http::response(['id' => basename($request->url())]),
    ]);

    $advisories = app(Osv::class)->advisories([$stdlib, $clean]);

    expect(array_column($advisories[$stdlib->id], 'osv_id'))->toBe(['GO-1', 'GO-2']);
    expect($advisories[$clean->id])->toBe([]);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'querybatch') && $request['queries'] === [
        ['package' => ['name' => 'stdlib', 'ecosystem' => 'Go'], 'version' => '1.16.0', 'page_token' => 'page-2'],
    ]);
});

test('a vulnerability record that failed during prefetch still fails grading instead of being dropped', function () {
    $lodash = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    Http::fake([
        'api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => [['id' => 'GHSA-1', 'modified' => '2026-10-01T00:00:00Z']]]]]),
        'api.osv.dev/v1/vulns/*' => Http::response('', 503),
    ]);

    app(Osv::class)->advisories([$lodash]);
})->throws(RequestException::class);
