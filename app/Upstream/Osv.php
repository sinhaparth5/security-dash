<?php

namespace App\Upstream;

use App\Enums\Ecosystem;
use App\Models\Dependency;
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
     * @return array<int, list<array{osv_id: string, aliases: list<string>, severity: string|null, summary: string|null, fixed_version: string|null}>>
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
     * One OSV querybatch call: results line up with the dependencies by index.
     *
     * @param  list<Dependency>  $dependencies
     * @return list<array{vulns?: list<array{id: string, modified: string}>}>
     */
    private function queryBatch(array $dependencies): array
    {
        return Http::acceptJson()->timeout(30)->retry(2, 500)
            ->post('https://api.osv.dev/v1/querybatch', [
                'queries' => array_map(fn (Dependency $dependency) => [
                    'package' => $this->package($dependency),
                    'version' => Releases::normalize($dependency->version),
                ], $dependencies),
            ])
            ->throw()
            ->json('results');
    }

    /**
     * The fields of an OSV vulnerability record this app reads.
     *
     * @return array{id: string, aliases?: list<string>, summary?: string, database_specific?: array{severity?: string}, affected?: list<array{package?: array{name?: string, ecosystem?: string}, ranges?: list<array{events?: list<array{fixed?: string}>}>}>}
     */
    private function vulnerability(string $id, string $modified): array
    {
        return Cache::remember("osv:vuln:{$id}:{$modified}", now()->addDay(), fn () => Http::acceptJson()->timeout(15)->retry(2, 500)
            ->get('https://api.osv.dev/v1/vulns/'.rawurlencode($id))
            ->throw()
            ->json());
    }

    /**
     * @param  array{id: string, aliases?: list<string>, summary?: string, database_specific?: array{severity?: string}, affected?: list<array{package?: array{name?: string, ecosystem?: string}, ranges?: list<array{events?: list<array{fixed?: string}>}>}>}  $vulnerability
     * @return array{osv_id: string, aliases: list<string>, severity: string|null, summary: string|null, fixed_version: string|null}
     */
    private function advisory(array $vulnerability, Dependency $dependency): array
    {
        $package = $this->package($dependency);
        $current = Releases::normalize($dependency->version);

        $fixedVersion = collect($vulnerability['affected'] ?? [])
            ->filter(fn (array $affected) => ($affected['package']['name'] ?? null) === $package['name']
                && ($affected['package']['ecosystem'] ?? null) === $package['ecosystem'])
            ->flatMap(fn (array $affected) => $affected['ranges'] ?? [])
            ->flatMap(fn (array $range) => $range['events'] ?? [])
            ->pluck('fixed')
            ->filter(fn (?string $fixed) => $fixed !== null && version_compare($fixed, $current, '>'))
            ->sort(fn (string $a, string $b) => version_compare($a, $b))
            ->first();

        return [
            'osv_id' => $vulnerability['id'],
            'aliases' => $vulnerability['aliases'] ?? [],
            'severity' => $vulnerability['database_specific']['severity'] ?? null,
            'summary' => $vulnerability['summary'] ?? null,
            'fixed_version' => $fixedVersion,
        ];
    }
}
