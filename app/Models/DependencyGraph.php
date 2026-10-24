<?php

namespace App\Models;

use App\Enums\Ecosystem;
use Illuminate\Support\Collection;

/**
 * A service's npm dependency graph by package name. Reporters keep one version per name, so a name
 * is one node; its edges are the ranges its lockfile entry requires. The roots are the service's own
 * package.json dependencies.
 * ponytail: nested duplicate versions collapse into one node; key nodes by name@version if that hides a path.
 */
class DependencyGraph
{
    /**
     * @var Collection<string, Dependency>
     */
    private Collection $packages;

    /**
     * @var array<string, list<string>> package => the packages that require it
     */
    private array $parents = [];

    /**
     * @var array<string, array<string, int>>
     */
    private array $ancestors = [];

    /**
     * @param  array<string, string|null>|null  $roots  the service's own dependencies and their ranges, null when unreported
     * @param  Collection<int, Dependency>  $dependencies
     */
    public function __construct(private ?array $roots, Collection $dependencies, private bool $usageScanned)
    {
        $this->packages = $dependencies->filter(fn (Dependency $dependency) => $dependency->ecosystem === Ecosystem::Npm)->keyBy('name');

        foreach ($this->packages as $name => $dependency) {
            foreach (array_keys($dependency->requires ?? []) as $child) {
                if ($this->packages->has($child)) {
                    $this->parents[$child][] = $name;
                }
            }
        }
    }

    /**
     * @param  Collection<int, Dependency>  $dependencies
     */
    public static function of(Service $service, Collection $dependencies): self
    {
        return new self($service->requires, $dependencies, $service->usage_scanned_at !== null);
    }

    /**
     * Whether the reporter sent the graph at all.
     */
    public function known(): bool
    {
        return $this->roots !== null;
    }

    public function isDirect(string $name): bool
    {
        return $this->roots !== null && array_key_exists($name, $this->roots);
    }

    public function package(string $name): ?Dependency
    {
        return $this->packages->get($name);
    }

    /**
     * The npm packages with advisories.
     *
     * @return Collection<string, Dependency>
     */
    public function vulnerable(): Collection
    {
        return $this->packages->filter(fn (Dependency $dependency) => $dependency->advisories->isNotEmpty());
    }

    /**
     * The range a parent declares for a child, or the service's own range when the parent is null.
     */
    public function range(?string $parent, string $child): ?string
    {
        return $parent === null ? $this->roots[$child] ?? null : $this->packages->get($parent)?->requires[$child] ?? null;
    }

    /**
     * @return list<string>
     */
    public function parents(string $name): array
    {
        return $this->parents[$name] ?? [];
    }

    /**
     * The shortest chain from each of the service's own dependencies that pulls the package in,
     * shortest first, e.g. [astro, vite, postcss, nanoid]. A direct dependency is its own chain.
     *
     * @return list<list<string>>
     */
    public function paths(string $name): array
    {
        if (! $this->known()) {
            return [];
        }

        $distance = $this->ancestors($name);
        $paths = [];

        // Ancestors come out breadth-first, so the paths are already shortest first.
        foreach (array_keys($distance) as $ancestor) {
            if (! $this->isDirect($ancestor)) {
                continue;
            }

            $path = [$ancestor];

            while (($current = end($path)) !== $name) {
                $next = collect(array_keys($this->packages[$current]->requires ?? []))
                    ->first(fn (string $child) => ($distance[$child] ?? null) === $distance[$current] - 1);

                if ($next === null) {
                    break;
                }

                $path[] = $next;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Does the service's own code load this package? True when the usage scan found it, or a package
     * that pulls it in, imported or run as a CLI; false when nothing that leads to it is used;
     * null without a scan or when the package isn't connected to package.json, since then there's
     * nothing to judge by.
     */
    public function reachable(Dependency $dependency): ?bool
    {
        if ($dependency->ecosystem !== Ecosystem::Npm || ! $this->usageScanned || ! $this->known()) {
            return null;
        }

        $ancestors = array_keys($this->ancestors($dependency->name));

        if (! collect($ancestors)->contains(fn (string $name) => $this->isDirect($name))) {
            return null;
        }

        return collect($ancestors)->contains(fn (string $name) => $this->isUsed($this->packages[$name]));
    }

    /**
     * How the usage scan saw a package: imported by app code, only by tooling (config, tests,
     * scripts or its CLI), or not at all. Null without a scan.
     *
     * @return 'source'|'tooling'|'unused'|null
     */
    public function usage(Dependency $dependency): ?string
    {
        if (! $this->usageScanned || $dependency->ecosystem !== Ecosystem::Npm || ($dependency->usage === null && ! $this->isDirect($dependency->name))) {
            return null;
        }

        $sites = collect($dependency->usage['sites'] ?? []);

        return match (true) {
            $sites->contains('kind', 'source') => 'source',
            $sites->isNotEmpty() || ($dependency->usage['bin'] ?? false) => 'tooling',
            default => 'unused',
        };
    }

    private function isUsed(Dependency $dependency): bool
    {
        // A direct dependency the scan didn't mention is assumed used, so a scan gap can't hide a finding.
        if ($dependency->usage === null) {
            return $this->isDirect($dependency->name);
        }

        return $dependency->usage['sites'] !== [] || $dependency->usage['bin'];
    }

    /**
     * Every package the given one can be reached from, with its distance in hops, nearest first.
     *
     * @return array<string, int>
     */
    private function ancestors(string $name): array
    {
        if (isset($this->ancestors[$name])) {
            return $this->ancestors[$name];
        }

        if (! $this->packages->has($name)) {
            return $this->ancestors[$name] = [];
        }

        $distance = [$name => 0];
        $queue = [$name];

        for ($index = 0; $index < count($queue); $index++) {
            foreach ($this->parents($queue[$index]) as $parent) {
                if (! isset($distance[$parent])) {
                    $distance[$parent] = $distance[$queue[$index]] + 1;
                    $queue[] = $parent;
                }
            }
        }

        return $this->ancestors[$name] = $distance;
    }
}
