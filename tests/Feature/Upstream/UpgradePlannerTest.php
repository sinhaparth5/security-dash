<?php

use App\Enums\Ecosystem;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\DependencyGraph;
use App\Models\Service;
use App\Upstream\UpgradePlanner;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * astro → vite → postcss → nanoid, as a lockfile reports it, with nanoid 3.3.6 vulnerable (fixed in 3.3.8).
 *
 * @param  array<string, array<string, array<string, string>>>  $registry  package => version => dependencies
 */
function plannedService(string $astroRange, array $registry, array $accepted = []): array
{
    Http::fake(collect($registry)->mapWithKeys(fn (array $versions, string $name) => [
        "registry.npmjs.org/{$name}" => Http::response(['versions' => collect($versions)->map(fn (array $dependencies) => ['dependencies' => $dependencies])->all()]),
    ])->all());

    $service = Service::factory()->create(['requires' => ['astro' => $astroRange]]);
    $chain = ['astro' => ['7.4.2', ['vite' => '6.0.0']], 'vite' => ['6.0.0', ['postcss' => '8.4.31']], 'postcss' => ['8.4.31', ['nanoid' => '3.3.6']], 'nanoid' => ['3.3.6', []]];

    foreach ($chain as $name => [$version, $requires]) {
        Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => $name, 'version' => $version, 'requires' => $requires]);
    }

    Advisory::factory()->create(['dependency_id' => $service->dependencies()->where('name', 'nanoid')->value('id'), 'osv_id' => 'GHSA-mwcw-c2x4-8c55', 'fixed_version' => '3.3.8']);

    return app(UpgradePlanner::class)->plan(DependencyGraph::of($service, $service->dependencies()->with('advisories')->get()), $accepted);
}

test('a fix today\'s ranges already resolve to is planned as a lockfile refresh', function () {
    $plan = plannedService('^7.4.2', [
        'astro' => ['7.4.2' => ['vite' => '^6.0.0'], '7.5.0' => ['vite' => '^6.0.0']],
        'vite' => ['6.0.0' => ['postcss' => '^8.4.31']],
        'postcss' => ['8.4.31' => ['nanoid' => '3.3.6'], '8.4.32' => ['nanoid' => '^3.3.8']],
        'nanoid' => ['3.3.6' => [], '3.3.8' => [], '5.0.0' => []],
    ]);

    expect($plan)->toBe([[
        'action' => 'refresh', 'package' => 'nanoid', 'from' => '3.3.6', 'to' => '3.3.8',
        'via' => ['astro', 'vite', 'postcss', 'nanoid'], 'fixes' => ['GHSA-mwcw-c2x4-8c55'], 'breaking' => false,
    ]]);
});

test('a pinned chain is planned as a bump of the first direct dependency release that pulls the fix in', function () {
    $plan = plannedService('7.4.2', [
        'astro' => ['7.4.2' => ['vite' => '6.0.0'], '7.4.3' => ['vite' => '6.0.0'], '7.6.0' => ['vite' => '^6.1.0'], '8.0.0' => ['vite' => '^7.0.0']],
        'vite' => ['6.0.0' => ['postcss' => '8.4.31'], '6.1.0' => ['postcss' => '^8.4.32']],
        'postcss' => ['8.4.31' => ['nanoid' => '3.3.6'], '8.4.32' => ['nanoid' => '^3.3.8']],
        'nanoid' => ['3.3.6' => [], '3.3.8' => []],
    ]);

    expect($plan)->toHaveCount(1)
        ->and($plan[0])->toMatchArray(['action' => 'bump', 'package' => 'astro', 'from' => '7.4.2', 'to' => '7.6.0', 'breaking' => false]);
});

test('a release that drops the vulnerable package from the chain counts as a fix', function () {
    $plan = plannedService('7.4.2', [
        'astro' => ['7.4.2' => ['vite' => '6.0.0'], '7.5.0' => ['vite' => '6.1.0']],
        'vite' => ['6.0.0' => ['postcss' => '8.4.31'], '6.1.0' => []],
        'postcss' => ['8.4.31' => ['nanoid' => '3.3.6']],
        'nanoid' => ['3.3.6' => []],
    ]);

    expect($plan[0])->toMatchArray(['action' => 'bump', 'package' => 'astro', 'to' => '7.5.0']);
});

test('when no release pulls the fix in an override is planned', function () {
    $plan = plannedService('7.4.2', [
        'astro' => ['7.4.2' => ['vite' => '6.0.0'], '7.4.3' => ['vite' => '6.0.0']],
        'vite' => ['6.0.0' => ['postcss' => '8.4.31']],
        'postcss' => ['8.4.31' => ['nanoid' => '3.3.6']],
        'nanoid' => ['3.3.6' => [], '3.3.8' => []],
    ]);

    expect($plan[0])->toMatchArray(['action' => 'override', 'package' => 'nanoid', 'from' => '3.3.6', 'to' => '3.3.8']);
});

test('accepted advisories are left out of the plan', function () {
    expect(plannedService('7.4.2', [], ['GHSA-mwcw-c2x4-8c55']))->toBe([]);
});
