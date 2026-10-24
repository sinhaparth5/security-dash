<?php

use App\Enums\Ecosystem;
use App\Models\Dependency;
use App\Upstream\SupplyChain;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('a release is flagged when it is brand new, runs install scripts, lost its provenance or changed publisher', function () {
    $suspect = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'event-stream', 'version' => '3.3.6']);
    $quiet = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.21']);

    Http::fake([
        'api.deps.dev/v3/systems/npm/packages/event-stream' => Http::response(['versions' => [
            ['versionKey' => ['version' => '3.3.5'], 'publishedAt' => now()->subYear()->toIso8601String()],
            ['versionKey' => ['version' => '3.3.6'], 'publishedAt' => now()->subHours(5)->toIso8601String()],
            ['versionKey' => ['version' => '4.0.0-beta.1'], 'publishedAt' => now()->toIso8601String()],
        ]]),
        'api.deps.dev/v3/systems/npm/packages/lodash' => Http::response(['versions' => [
            ['versionKey' => ['version' => '4.17.20'], 'publishedAt' => now()->subYears(5)->toIso8601String()],
            ['versionKey' => ['version' => '4.17.21'], 'publishedAt' => now()->subYears(4)->toIso8601String()],
        ]]),
        'registry.npmjs.org/event-stream/3.3.6' => Http::response(['scripts' => ['postinstall' => 'node ./flatmap.js', 'test' => 'tape'], 'dist' => [], '_npmUser' => ['name' => 'right9ctrl']]),
        'registry.npmjs.org/event-stream/3.3.5' => Http::response(['scripts' => [], 'dist' => ['attestations' => ['url' => 'x']], '_npmUser' => ['name' => 'dominictarr']]),
        'registry.npmjs.org/lodash/*' => Http::response(['dist' => [], '_npmUser' => ['name' => 'jdalton']]),
    ]);

    $signals = app(SupplyChain::class)->signals([$suspect, $quiet]);

    expect(array_column($signals[$suspect->id], 'signal'))->toBe(['fresh', 'install_script', 'provenance_dropped', 'new_publisher'])
        ->and($signals[$suspect->id][1]['detail'])->toContain('postinstall: node ./flatmap.js')
        ->and($signals[$suspect->id][3]['detail'])->toBe('Published by right9ctrl; 3.3.5 was published by dominictarr.')
        ->and($signals)->not->toHaveKey($quiet->id);
});

test('a registry or deps.dev failure leaves packages without signals instead of failing', function () {
    $dependency = Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.21']);
    Http::fake(['*' => Http::response(null, 503)]);

    expect(app(SupplyChain::class)->signals([$dependency]))->toBe([]);
});
