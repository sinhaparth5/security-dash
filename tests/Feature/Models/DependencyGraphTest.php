<?php

use App\Enums\Ecosystem;
use App\Models\Dependency;
use App\Models\DependencyGraph;
use App\Models\Service;

/**
 * astro and marked are the service's own; both reach nanoid, astro through vite and postcss.
 *
 * @param  array<string, array{sites: list<array{file: string, line: int, kind: string}>, bin: bool}>  $usage
 */
function graphOf(array $usage, bool $scanned = true): DependencyGraph
{
    $service = Service::factory()->make(['requires' => ['astro' => '^7.4.2', 'marked' => '^15.0.0'], 'usage_scanned_at' => $scanned ? now() : null]);
    $requires = ['astro' => ['vite' => '^6.0.0'], 'vite' => ['postcss' => '^8.4.0'], 'postcss' => ['nanoid' => '^3.3.6'], 'marked' => ['nanoid' => '^3.0.0'], 'nanoid' => [], 'orphan' => []];

    return DependencyGraph::of($service, collect($requires)->map(fn (array $children, string $name) => Dependency::factory()->make([
        'ecosystem' => Ecosystem::Npm, 'name' => $name, 'requires' => $children, 'usage' => $usage[$name] ?? null,
    ]))->values());
}

test('paths run from each direct dependency that pulls a package in, shortest first', function () {
    expect(graphOf([])->paths('nanoid'))->toBe([['marked', 'nanoid'], ['astro', 'vite', 'postcss', 'nanoid']])
        ->and(graphOf([])->paths('astro'))->toBe([['astro']])
        ->and(graphOf([])->paths('orphan'))->toBe([]);
});

test('a package is reachable when its code or any package leading to it is used', function (array $usage, ?bool $expected) {
    $graph = graphOf($usage);

    expect($graph->reachable($graph->package('nanoid')))->toBe($expected);
})->with([
    'a direct dependency is imported' => [['astro' => ['sites' => [['file' => 'src/a.ts', 'line' => 1, 'kind' => 'source']], 'bin' => false], 'marked' => ['sites' => [], 'bin' => false]], true],
    'a direct dependency runs as a CLI' => [['astro' => ['sites' => [], 'bin' => true], 'marked' => ['sites' => [], 'bin' => false]], true],
    'the package itself is imported' => [['astro' => ['sites' => [], 'bin' => false], 'marked' => ['sites' => [], 'bin' => false], 'nanoid' => ['sites' => [['file' => 'src/id.ts', 'line' => 3, 'kind' => 'source']], 'bin' => false]], true],
    'nothing leading to it is used' => [['astro' => ['sites' => [], 'bin' => false], 'marked' => ['sites' => [], 'bin' => false]], false],
    'a direct dependency the scan did not mention' => [['astro' => ['sites' => [], 'bin' => false]], true],
]);

test('reachability is unknown without a usage scan or a route from package.json', function () {
    expect(graphOf([], scanned: false)->reachable(graphOf([], scanned: false)->package('nanoid')))->toBeNull()
        ->and(graphOf([])->reachable(graphOf([])->package('orphan')))->toBeNull();
});

test('usage labels say whether app code, only tooling or nothing imports a package', function () {
    $graph = graphOf([
        'astro' => ['sites' => [['file' => 'astro.config.mjs', 'line' => 1, 'kind' => 'tooling'], ['file' => 'src/pages/index.astro', 'line' => 2, 'kind' => 'source']], 'bin' => true],
        'marked' => ['sites' => [], 'bin' => true],
    ]);

    expect($graph->usage($graph->package('astro')))->toBe('source')
        ->and($graph->usage($graph->package('marked')))->toBe('tooling')
        ->and($graph->usage($graph->package('vite')))->toBeNull()
        ->and(($unused = graphOf(['marked' => ['sites' => [], 'bin' => false]]))->usage($unused->package('marked')))->toBe('unused');
});
