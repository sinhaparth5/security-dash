<?php

namespace App\Upstream;

use App\Enums\Ecosystem;
use App\Models\Dependency;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Osv
{
    /**
     * Look up known vulnerabilities for each dependency's running version.
     *
     * Throws on upstream failure so a caller never mistakes an outage for "no advisories".
     *
     * @param  iterable<Dependency>  $dependencies
     * @return array<int, list<array{osv_id: string, aliases: list<string>, severity: string|null, summary: string|null, fixed_version: string|null, affected_packages: list<string>, affected_symbols: list<string>}>>
     */
    public function advisories(iterable $dependencies): array
    {
        $queryable = collect($dependencies)
            ->filter(fn (Dependency $dependency) => $this->package($dependency) !== null)
            ->values();

        $advisories = [];

        foreach ($queryable->chunk(1000) as $chunk) {
            $batch = array_values($chunk->all());
            $results = $this->queryBatch($batch);
            $this->prefetch(collect($results)->flatMap(fn (array $result) => $result['vulns'] ?? []));

            foreach ($batch as $index => $dependency) {
                $advisories[$dependency->id] = array_map(
                    fn (array $vuln) => $this->advisory($this->vulnerability($vuln['id'], $vuln['modified']), $dependency),
                    $results[$index]['vulns'] ?? [],
                );
            }
        }

        return $advisories;
    }

    /**
     * The OSV package coordinates for a dependency, or null when OSV does not track it.
     *
     * @return array{name: string, ecosystem: string}|null
     */
    private function package(Dependency $dependency): ?array
    {
        return match (true) {
            $dependency->ecosystem === Ecosystem::Go => ['name' => $dependency->name, 'ecosystem' => 'Go'],
            $dependency->ecosystem === Ecosystem::Npm => ['name' => $dependency->name, 'ecosystem' => 'npm'],
            $dependency->ecosystem === Ecosystem::Runtime && $dependency->name === 'go' => ['name' => 'stdlib', 'ecosystem' => 'Go'],
            default => null,
        };
    }

    /**
     * OSV querybatch results, lined up with the dependencies by index. OSV pages a query past ~1,000 vulns,
     * or a batch past ~3,000, so pages are followed until none is left: a dropped page would read as no advisories.
     *
     * @param  list<Dependency>  $dependencies
     * @return list<array{vulns?: list<array{id: string, modified: string}>}>
     */
    private function queryBatch(array $dependencies): array
    {
        $results = array_fill(0, count($dependencies), ['vulns' => []]);
        $tokens = array_fill(0, count($dependencies), null);

        do {
            $pending = array_keys($tokens);
            $page = Http::acceptJson()->timeout(30)->retry(2, 500)
                ->post('https://api.osv.dev/v1/querybatch', [
                    'queries' => array_map(fn (int $index) => [
                        'package' => $this->package($dependencies[$index]),
                        'version' => Releases::normalize($dependencies[$index]->version),
                        ...($tokens[$index] === null ? [] : ['page_token' => $tokens[$index]]),
                    ], $pending),
                ])
                ->throw()
                ->json('results');

            $tokens = [];

            foreach ($pending as $position => $index) {
                array_push($results[$index]['vulns'], ...$page[$position]['vulns'] ?? []);

                if (isset($page[$position]['next_page_token'])) {
                    $tokens[$index] = $page[$position]['next_page_token'];
                }
            }
        } while ($tokens !== []);

        return $results;
    }

    /**
     * The fields of an OSV vulnerability record this app reads.
     *
     * @return array{id: string, aliases?: list<string>, summary?: string, database_specific?: array{severity?: string}, affected?: list<array{package?: array{name?: string, ecosystem?: string}, ranges?: list<array{events?: list<array{fixed?: string}>}>, ecosystem_specific?: array{imports?: list<array{path: string, symbols?: list<string>}>}}>}
     */
    private function vulnerability(string $id, string $modified): array
    {
        return Cache::remember(self::vulnerabilityKey($id, $modified), now()->addDay(), fn () => Http::acceptJson()->timeout(15)->retry(2, 500)
            ->get(self::vulnerabilityUrl($id))
            ->throw()
            ->json());
    }

    /**
     * Fetch the vulnerability records not cached yet, 25 at a time, so a cold cache grades inside the job's timeout.
     * Only records that came back are cached: a failure is left for vulnerability(), which retries and throws.
     *
     * @param  Collection<int, array{id: string, modified: string}>  $vulns
     */
    private function prefetch(Collection $vulns): void
    {
        $missing = $vulns->mapWithKeys(fn (array $vuln) => [self::vulnerabilityKey($vuln['id'], $vuln['modified']) => $vuln['id']])
            ->reject(fn (string $id, string $key) => Cache::has($key));

        foreach ($missing->chunk(25) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => $batch->map(fn (string $id, string $key) => $pool->as($key)->acceptJson()->timeout(15)->get(self::vulnerabilityUrl($id)))->all());

            foreach ($batch->keys() as $key) {
                $response = $responses[$key] ?? null;

                if ($response instanceof Response && $response->successful() && is_array($response->json())) {
                    Cache::put($key, $response->json(), now()->addDay());
                }
            }
        }
    }

    private static function vulnerabilityKey(string $id, string $modified): string
    {
        return "osv:vuln:{$id}:{$modified}";
    }

    private static function vulnerabilityUrl(string $id): string
    {
        return 'https://api.osv.dev/v1/vulns/'.rawurlencode($id);
    }

    /**
     * @param  array{id: string, aliases?: list<string>, summary?: string, database_specific?: array{severity?: string}, affected?: list<array{package?: array{name?: string, ecosystem?: string}, ranges?: list<array{events?: list<array{fixed?: string}>}>, ecosystem_specific?: array{imports?: list<array{path: string, symbols?: list<string>}>}}>}  $vulnerability
     * @return array{osv_id: string, aliases: list<string>, severity: string|null, summary: string|null, fixed_version: string|null, affected_packages: list<string>, affected_symbols: list<string>}
     */
    private function advisory(array $vulnerability, Dependency $dependency): array
    {
        $package = $this->package($dependency);
        $current = Releases::normalize($dependency->version);

        $affected = collect($vulnerability['affected'] ?? [])
            ->filter(fn (array $affected) => ($affected['package']['name'] ?? null) === $package['name']
                && ($affected['package']['ecosystem'] ?? null) === $package['ecosystem']);

        $fixedVersion = $affected
            ->flatMap(fn (array $affected) => $affected['ranges'] ?? [])
            ->flatMap(fn (array $range) => $range['events'] ?? [])
            ->pluck('fixed')
            ->filter(fn (?string $fixed) => $fixed !== null && version_compare($fixed, $current, '>'))
            ->sort(fn (string $a, string $b) => version_compare($a, $b))
            ->first();

        $imports = array_merge(...$affected->map(fn (array $affected) => $affected['ecosystem_specific']['imports'] ?? [])->values()->all());

        return [
            'osv_id' => $vulnerability['id'],
            'aliases' => $vulnerability['aliases'] ?? [],
            'severity' => $vulnerability['database_specific']['severity'] ?? null,
            'summary' => $vulnerability['summary'] ?? null,
            'fixed_version' => $fixedVersion,
            // Go advisories name the vulnerable packages inside the module, e.g. only x/crypto/openpgp.
            'affected_packages' => array_values(array_unique(array_column($imports, 'path'))),
            // ...and usually the functions in them, which a symbol scan checks against the binary.
            'affected_symbols' => array_values(array_unique(array_merge(...array_map(
                fn (array $import) => array_map(fn (string $symbol) => $import['path'].'.'.$symbol, $import['symbols'] ?? []),
                $imports,
            )))),
        ];
    }
}
