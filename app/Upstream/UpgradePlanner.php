<?php

namespace App\Upstream;

use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\DependencyGraph;

class UpgradePlanner
{
    /**
     * How many newer releases of a direct dependency to try before falling back to an override.
     * ponytail: linear scan of releases; bisect if packages with thousands of releases make plans slow.
     */
    private const MAX_CANDIDATES = 150;

    public function __construct(private Npm $npm, private SupplyChain $supplyChain) {}

    /**
     * The fewest changes that clear the service's fixable npm advisories, cheapest first:
     *  - refresh: today's ranges already resolve to a fixed version, so `npm update` is enough;
     *  - bump: the first release of a direct dependency whose own dependencies pull the fix in
     *    (for a vulnerable direct dependency, its first fixed release);
     *  - override: no release of the direct dependency pulls it in yet, so pin it with npm overrides.
     * Ranges are resolved to their highest match, as a fresh install would.
     *
     * @param  list<string>  $accepted  OSV ids under an active risk acceptance
     * @return list<array{action: 'refresh'|'bump'|'override', package: string, from: string|null, to: string, breaking: bool, fixes: list<string>, via: list<string>}>
     */
    public function plan(DependencyGraph $graph, array $accepted): array
    {
        $steps = [];

        foreach ($graph->vulnerable() as $dependency) {
            $fixes = $dependency->advisories
                ->filter(fn (Advisory $advisory) => $advisory->fixed_version !== null && ! $advisory->isMalicious() && ! in_array($advisory->osv_id, $accepted, true));

            if ($fixes->isEmpty()) {
                continue;
            }

            $ids = array_values(array_unique(array_map(fn (Advisory $advisory) => $advisory->osv_id, $fixes->all())));
            // Every advisory has to be cleared, so the newest fix is the one to reach.
            $need = (string) $fixes->pluck('fixed_version')->sort(fn (string $a, string $b) => Semver::compare($a, $b))->last();

            foreach ($this->stepsFor($graph, $dependency, $need) as $step) {
                $key = $step['action'].'|'.$step['package'];
                $existing = $steps[$key] ?? null;

                $steps[$key] = [
                    ...$step,
                    'to' => $existing !== null && Semver::compare($existing['to'], $step['to']) > 0 ? $existing['to'] : $step['to'],
                    'fixes' => array_values(array_unique([...$existing['fixes'] ?? [], ...$ids])),
                    'via' => $existing['via'] ?? $step['via'],
                ];
            }
        }

        $order = ['refresh' => 0, 'bump' => 1, 'override' => 2];
        $plan = array_map(fn (array $step) => [...$step, 'breaking' => $step['from'] !== null && Semver::satisfies($step['to'], '^'.$step['from']) === false], array_values($steps));

        usort($plan, fn (array $a, array $b) => [$order[$a['action']], $a['breaking'], -count($a['fixes']), $a['package']]
            <=> [$order[$b['action']], $b['breaking'], -count($b['fixes']), $b['package']]);

        return $plan;
    }

    /**
     * @return list<array{action: 'refresh'|'bump'|'override', package: string, from: string|null, to: string, via: list<string>}>
     */
    private function stepsFor(DependencyGraph $graph, Dependency $dependency, string $need): array
    {
        $paths = $graph->paths($dependency->name);
        $fixes = fn (string|false|null $landed) => $landed === false || ($landed !== null && Semver::compare($landed, $need) >= 0);

        // A fresh resolve of today's ranges, top to bottom, is what `npm update` would do.
        $refreshed = array_map(function (array $path) use ($graph) {
            $range = $graph->range(null, $path[0]);
            $top = $range === null ? null : Semver::maxSatisfying(array_keys($this->npm->versions($path[0])), $range);

            return $top === null ? null : $this->land($path, $top);
        }, $paths);

        if ($paths !== [] && collect($refreshed)->every($fixes)) {
            $landed = collect($refreshed)->filter(fn (string|false|null $version) => is_string($version))->sort(fn (string $a, string $b) => Semver::compare($a, $b))->first();

            return [['action' => 'refresh', 'package' => $dependency->name, 'from' => $dependency->version, 'to' => $landed ?? $need, 'via' => $paths[0]]];
        }

        $steps = [];

        foreach ($paths as $path) {
            $top = $graph->package($path[0]);
            $release = $top === null ? null : $this->firstRelease($path[0], $top->version, fn (string $version) => $fixes($this->land($path, $version)));

            $steps[] = $release === null
                ? ['action' => 'override', 'package' => $dependency->name, 'from' => $dependency->version, 'to' => $need, 'via' => $path]
                : ['action' => 'bump', 'package' => $path[0], 'from' => $top->version, 'to' => $release, 'via' => $path];
        }

        return $steps;
    }

    /**
     * Follow the chain down from the given release of its top package, taking the highest version each
     * range allows, as a fresh install would: the version of the last package it lands on, false when a
     * release on the way no longer depends on the next package (the chain is gone), null when a range can't be resolved.
     *
     * @param  list<string>  $path
     */
    private function land(array $path, string $version): string|false|null
    {
        for ($hop = 1; $hop < count($path); $hop++) {
            $range = $this->npm->versions($path[$hop - 1])[$version]['dependencies'][$path[$hop]] ?? null;

            if ($range === null) {
                return false;
            }

            $version = Semver::maxSatisfying(array_keys($this->npm->versions($path[$hop])), $range);

            if ($version === null) {
                return null;
            }
        }

        return $version;
    }

    /**
     * The lowest stable, non-deprecated release after the given version that passes the check. Releases
     * under SupplyChain::FRESH_HOURS old are passed over: a fix is not worth a release nobody has vetted yet.
     *
     * @param  callable(string): bool  $accepts
     */
    private function firstRelease(string $name, string $after, callable $accepts): ?string
    {
        return collect($this->npm->versions($name))
            ->filter(fn (array $release, string $version) => ! $release['deprecated']
                && (Semver::parse($version)[3] ?? null) === []
                && Semver::compare($version, $after) > 0)
            ->keys()
            ->sort(fn (string $a, string $b) => Semver::compare($a, $b))
            ->take(self::MAX_CANDIDATES)
            ->first(fn (string $version) => ! $this->supplyChain->isFresh($name, $version) && $accepts($version));
    }
}
