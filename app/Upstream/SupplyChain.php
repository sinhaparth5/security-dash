<?php

namespace App\Upstream;

use App\Enums\Ecosystem;
use App\Models\Dependency;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Release signals that don't make a package vulnerable but are how supply-chain attacks look from
 * the outside: a brand-new release, an install script, a provenance attestation that went missing,
 * or a different account publishing than last time. Publish dates come from deps.dev, the rest from
 * the npm registry's per-version manifests.
 */
class SupplyChain
{
    /**
     * Releases younger than this haven't had time to be caught: most malicious versions are pulled within days.
     */
    public const FRESH_HOURS = 72;

    /**
     * npm lifecycle scripts that run on `npm install`.
     */
    private const INSTALL_SCRIPTS = ['preinstall', 'install', 'postinstall'];

    /**
     * @var array<string, array<string, string|null>>
     */
    private array $published = [];

    /**
     * The signals for each npm dependency that has any, by dependency id.
     *
     * @param  iterable<Dependency>  $dependencies
     * @return array<int, list<array{signal: string, detail: string}>>
     */
    public function signals(iterable $dependencies): array
    {
        $npm = collect($dependencies)->filter(fn (Dependency $dependency) => $dependency->ecosystem === Ecosystem::Npm)->values();
        $this->loadPublished(array_values(array_unique($npm->map(fn (Dependency $dependency) => $dependency->name)->all())));

        $previous = $npm->mapWithKeys(fn (Dependency $dependency) => [$dependency->name => $this->previousRelease($dependency->name, $dependency->version)]);
        $manifests = $this->manifests(array_values(array_unique([
            ...$npm->map(fn (Dependency $dependency) => $dependency->name.'@'.$dependency->version)->all(),
            ...$previous->filter()->map(fn (string $version, string $name) => $name.'@'.$version)->values()->all(),
        ])));

        $signals = [];

        foreach ($npm as $dependency) {
            $current = $manifests[$dependency->name.'@'.$dependency->version] ?? null;
            $before = $previous[$dependency->name] === null ? null : $manifests[$dependency->name.'@'.$previous[$dependency->name]] ?? null;
            $found = [];

            if ($this->isFresh($dependency->name, $dependency->version)) {
                $found[] = ['signal' => 'fresh', 'detail' => 'Published '.Carbon::parse((string) $this->published[$dependency->name][$dependency->version])->diffForHumans().'. Brand-new releases are where hijacked packages show up first.'];
            }

            if ($current !== null && $current['scripts'] !== []) {
                $hook = array_key_first($current['scripts']);
                $found[] = ['signal' => 'install_script', 'detail' => "Runs code on install ({$hook}: ".mb_strimwidth($current['scripts'][$hook], 0, 120, '…').').'];
            }

            if ($current !== null && $before !== null && $before['provenance'] && ! $current['provenance']) {
                $found[] = ['signal' => 'provenance_dropped', 'detail' => "Published without the provenance attestation {$previous[$dependency->name]} had, so it may not have been built by the project's CI."];
            }

            if ($current !== null && $before !== null && $current['publisher'] !== null && $before['publisher'] !== null && $current['publisher'] !== $before['publisher']) {
                $found[] = ['signal' => 'new_publisher', 'detail' => "Published by {$current['publisher']}; {$previous[$dependency->name]} was published by {$before['publisher']}."];
            }

            if ($found !== []) {
                $signals[$dependency->id] = $found;
            }
        }

        return $signals;
    }

    /**
     * Whether a release came out within FRESH_HOURS. Unknown publish dates count as not fresh.
     */
    public function isFresh(string $name, string $version): bool
    {
        if (! isset($this->published[$name])) {
            // ponytail: best effort, a deps.dev outage means nothing counts as fresh until the next evaluation.
            rescue(fn () => $this->loadPublished([$name]), report: true);
            $this->published[$name] ??= [];
        }

        $published = $this->published[$name][$version] ?? null;

        return $published !== null && Carbon::parse($published)->gt(now()->subHours(self::FRESH_HOURS));
    }

    /**
     * The highest stable release below the given version: the one to compare a release against.
     */
    private function previousRelease(string $name, string $version): ?string
    {
        return collect(array_keys($this->published[$name] ?? []))
            ->filter(fn (string $candidate) => (Semver::parse($candidate)[3] ?? null) === [] && Semver::compare($candidate, $version) < 0)
            ->sort(fn (string $a, string $b) => Semver::compare($a, $b))
            ->last();
    }

    /**
     * When each version of each package was published, from deps.dev. A failed lookup leaves the package without dates.
     *
     * @param  list<string>  $names
     */
    private function loadPublished(array $names): void
    {
        $missing = array_values(array_filter($names, fn (string $name) => ! isset($this->published[$name])));

        $found = $this->fetch(
            array_combine($missing, array_map(fn (string $name) => 'https://api.deps.dev/v3/systems/npm/packages/'.rawurlencode($name), $missing)),
            'depsdev:published:',
            now()->addHours(6),
            function (array $package) {
                $dates = [];

                foreach ((array) ($package['versions'] ?? []) as $version) {
                    if (is_array($version) && is_string($version['versionKey']['version'] ?? null)) {
                        $dates[$version['versionKey']['version']] = is_string($version['publishedAt'] ?? null) ? $version['publishedAt'] : null;
                    }
                }

                return $dates;
            },
        );

        foreach ($missing as $name) {
            $this->published[$name] = array_filter(
                (array) ($found[$name] ?? []),
                fn (mixed $date, int|string $version) => is_string($version) && ($date === null || is_string($date)),
                ARRAY_FILTER_USE_BOTH,
            );
        }
    }

    /**
     * The parts of each "name@version" manifest these signals read. Published manifests don't change, so they're kept a month.
     *
     * @param  list<string>  $releases
     * @return array<string, array{scripts: array<string, string>, provenance: bool, publisher: string|null}>
     */
    private function manifests(array $releases): array
    {
        $found = $this->fetch(
            array_combine($releases, array_map(function (string $release) {
                $at = strrpos($release, '@');

                return 'https://registry.npmjs.org/'.substr($release, 0, (int) $at).'/'.rawurlencode(substr($release, (int) $at + 1));
            }, $releases)),
            'npm:manifest:',
            now()->addMonth(),
            fn (array $manifest) => [
                'scripts' => array_filter(array_intersect_key((array) ($manifest['scripts'] ?? []), array_flip(self::INSTALL_SCRIPTS)), 'is_string'),
                'provenance' => isset($manifest['dist']['attestations']),
                'publisher' => is_string($manifest['_npmUser']['name'] ?? null) ? $manifest['_npmUser']['name'] : null,
            ],
        );

        // Cached entries come back untyped, so read every one back into shape.
        return array_map(fn (mixed $manifest) => [
            'scripts' => array_filter((array) (is_array($manifest) ? $manifest['scripts'] ?? [] : []), 'is_string'),
            'provenance' => is_array($manifest) && ($manifest['provenance'] ?? false) === true,
            'publisher' => is_array($manifest) && is_string($manifest['publisher'] ?? null) ? $manifest['publisher'] : null,
        ], array_filter($found));
    }

    /**
     * GET each URL not already cached, 25 at a time, and cache what the reader keeps of it.
     * Responses that fail are left out and retried next time; a 404 is cached as empty.
     *
     * @param  array<string, string>  $urls  key => URL
     * @param  Closure(array<mixed>): array<mixed>  $read
     * @return array<string, mixed>
     */
    private function fetch(array $urls, string $prefix, CarbonInterface $ttl, Closure $read): array
    {
        $found = [];
        $missing = [];

        foreach ($urls as $key => $url) {
            $cached = Cache::get($prefix.$key);
            is_array($cached) ? $found[$key] = $cached : $missing[$key] = $url;
        }

        // ponytail: 25 concurrent requests per batch, no global rate limit; add one if deps.dev or npm start throttling.
        foreach (array_chunk($missing, 25, true) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $key, string $url) => $pool->as($key)->acceptJson()->timeout(15)->get($url),
                array_keys($batch),
                $batch,
            ));

            foreach ($responses as $key => $response) {
                if (! $response instanceof Response || (! $response->successful() && ! $response->notFound())) {
                    continue;
                }

                $found[$key] = $response->notFound() ? [] : $read((array) $response->json());
                Cache::put($prefix.$key, $found[$key], $ttl);
            }
        }

        return $found;
    }
}
