<?php

namespace App\Upstream;

use App\Enums\Ecosystem;
use App\Models\Dependency;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Releases
{
    /**
     * Strip the "v" / "go" prefixes ecosystems put on versions so version_compare() can read them.
     */
    public static function normalize(string $version): string
    {
        return preg_replace('/^(go|v)/', '', $version);
    }

    /**
     * The newest upstream version for a dependency, or null when it cannot be determined
     * (private package, unsupported ecosystem).
     *
     * Runtimes compare within their release line (Node major, Go minor) so a healthy
     * LTS deployment is not flagged just because a newer major exists.
     */
    public function latest(Dependency $dependency): ?string
    {
        return match ($dependency->ecosystem) {
            Ecosystem::Runtime => match ($dependency->name) {
                'go' => $this->latestInLine($this->goReleases(), $dependency->version, 2),
                'node' => $this->latestInLine(array_column($this->nodeReleases(), 'version'), $dependency->version, 1),
                default => null,
            },
            Ecosystem::Npm => $this->packageLatest($this->npmUrl($dependency->name), 'version'),
            Ecosystem::Go => $this->packageLatest($this->goUrl($dependency->name), 'Version'),
            default => null,
        };
    }

    /**
     * A synthetic advisory when a newer security release exists in the running Node line.
     * OSV does not track the Node runtime itself, so the release index is the source.
     *
     * @return array{osv_id: string, aliases: list<string>, severity: string|null, summary: string|null, fixed_version: string|null}|null
     */
    public function nodeSecurityAdvisory(Dependency $dependency): ?array
    {
        if ($dependency->ecosystem !== Ecosystem::Runtime || $dependency->name !== 'node') {
            return null;
        }

        $current = self::normalize($dependency->version);

        $securityRelease = collect($this->nodeReleases())
            ->filter(fn (array $release) => $release['security']
                && $this->major($release['version']) === $this->major($current)
                && version_compare($release['version'], $current, '>'))
            ->sort(fn (array $a, array $b) => version_compare($a['version'], $b['version']))
            ->first();

        if ($securityRelease === null) {
            return null;
        }

        return [
            'osv_id' => 'NODE-'.$securityRelease['version'],
            'aliases' => [],
            'severity' => null,
            'summary' => "Node.js {$securityRelease['version']} is a security release; this runtime is older.",
            'fixed_version' => $securityRelease['version'],
        ];
    }

    /**
     * When the running runtime's release line stops getting security fixes, from endoflife.date.
     * Null for anything that is not a Node or Go runtime, or a line endoflife.date does not list.
     */
    public function endOfLife(Dependency $dependency): ?string
    {
        if ($dependency->ecosystem !== Ecosystem::Runtime) {
            return null;
        }

        $product = match ($dependency->name) {
            'node' => 'nodejs',
            'go' => 'go',
            default => null,
        };

        if ($product === null) {
            return null;
        }

        // Node lines are majors ("22"), Go lines are minors ("1.25").
        $parts = explode('.', self::normalize($dependency->version));
        $cycle = implode('.', array_slice($parts, 0, $product === 'go' ? 2 : 1));

        return $this->endOfLifeDates($product)[$cycle] ?? null;
    }

    /**
     * A synthetic advisory once the runtime's release line is past end of life:
     * no more security fixes means every future advisory stays unpatched.
     *
     * @return array{osv_id: string, aliases: list<string>, severity: string|null, summary: string|null, fixed_version: string|null}|null
     */
    public function endOfLifeAdvisory(Dependency $dependency, ?string $endOfLife): ?array
    {
        if ($endOfLife === null || $endOfLife > now()->toDateString()) {
            return null;
        }

        $line = $dependency->name === 'go'
            ? implode('.', array_slice(explode('.', self::normalize($dependency->version)), 0, 2))
            : $this->major(self::normalize($dependency->version));

        return [
            'osv_id' => "EOL-{$dependency->name}-{$line}",
            'aliases' => [],
            'severity' => 'HIGH',
            'summary' => ucfirst($dependency->name)." {$line} reached end of life on {$endOfLife} and no longer gets security fixes. Move to a supported release line.",
            'fixed_version' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function endOfLifeDates(string $product): array
    {
        return Cache::remember("upstream:eol:{$product}", now()->addDay(), fn () => Http::acceptJson()->timeout(15)->retry(2, 500)
            ->get("https://endoflife.date/api/v1/products/{$product}")->throw()->collect('result.releases')
            ->filter(fn (array $release) => is_string($release['eolFrom'] ?? null))
            ->mapWithKeys(fn (array $release) => [(string) $release['name'] => $release['eolFrom']])
            ->all());
    }

    /**
     * @param  list<string>  $versions
     */
    private function latestInLine(array $versions, string $current, int $lineParts): ?string
    {
        $current = self::normalize($current);
        $line = implode('.', array_slice(explode('.', $current), 0, $lineParts));

        $sorted = collect($versions)->sort(fn (string $a, string $b) => version_compare($b, $a))->values();

        return $sorted->first(fn (string $version) => str_starts_with($version.'.', $line.'.'))
            ?? $sorted->first();
    }

    /**
     * @return list<string>
     */
    private function goReleases(): array
    {
        return Cache::remember('upstream:go-releases', now()->addHours(6), fn () => array_values(
            Http::acceptJson()->timeout(15)->retry(2, 500)->get('https://go.dev/dl/?mode=json')->throw()->collect()
                ->where('stable', true)->map(fn (array $release) => self::normalize($release['version']))->all()
        ));
    }

    /**
     * @return list<array{version: string, security: bool}>
     */
    private function nodeReleases(): array
    {
        return Cache::remember('upstream:node-releases', now()->addHours(6), fn () => array_values(
            Http::acceptJson()->timeout(15)->retry(2, 500)->get('https://nodejs.org/dist/index.json')->throw()->collect()
                ->map(fn (array $release) => [
                    'version' => self::normalize($release['version']),
                    'security' => (bool) $release['security'],
                ])->all()
        ));
    }

    /**
     * Look up the latest npm and Go versions 25 at a time, so a big manifest grades inside the job's timeout.
     * Only answers are cached: anything that failed is left for latest(), which asks again and throws.
     *
     * @param  iterable<Dependency>  $dependencies
     */
    public function prefetch(iterable $dependencies): void
    {
        $missing = collect($dependencies)
            ->mapWithKeys(fn (Dependency $dependency) => match ($dependency->ecosystem) {
                Ecosystem::Npm => [$this->npmUrl($dependency->name) => 'version'],
                Ecosystem::Go => [$this->goUrl($dependency->name) => 'Version'],
                default => [],
            })
            ->reject(fn (string $field, string $url) => Cache::has(self::latestKey($url)));

        foreach ($missing->chunk(25) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => $batch->map(fn (string $field, string $url) => $pool->as($url)->acceptJson()->timeout(15)->get($url))->all());

            foreach ($batch as $url => $field) {
                $response = $responses[$url] ?? null;
                $latest = $response instanceof Response ? self::readLatest($response, $field) : null;

                if ($latest !== null) {
                    Cache::put(self::latestKey($url), $latest, now()->addHours(6));
                }
            }
        }
    }

    private function packageLatest(string $url, string $field): ?string
    {
        $latest = Cache::remember(self::latestKey($url), now()->addHours(6), function () use ($url, $field) {
            $response = Http::acceptJson()->timeout(15)->get($url);
            $latest = self::readLatest($response, $field);

            // An outage must fail the evaluation, never read as "no newer version".
            if ($latest === null) {
                $response->throw();

                throw new RuntimeException("{$url} answered without a {$field}.");
            }

            return $latest;
        });

        return $latest === '' ? null : $latest;
    }

    /**
     * The normalized version in a registry answer, '' for a package the registry doesn't have (cached, unlike null),
     * or null when the response isn't an answer.
     */
    private static function readLatest(Response $response, string $field): ?string
    {
        if ($response->notFound() || $response->status() === 410) {
            return '';
        }

        $version = $response->successful() ? $response->json($field) : null;

        return is_string($version) ? self::normalize($version) : null;
    }

    private static function latestKey(string $url): string
    {
        return 'upstream:latest:'.sha1($url);
    }

    private function npmUrl(string $name): string
    {
        return 'https://registry.npmjs.org/'.str_replace('/', '%2F', $name).'/latest';
    }

    private function goUrl(string $module): string
    {
        return 'https://proxy.golang.org/'.$this->escapeModulePath($module).'/@latest';
    }

    /**
     * The Go module proxy escapes uppercase letters as "!" + lowercase.
     */
    private function escapeModulePath(string $module): string
    {
        return preg_replace_callback('/[A-Z]/', fn (array $match) => '!'.strtolower($match[0]), $module);
    }

    private function major(string $version): string
    {
        return explode('.', $version)[0];
    }
}
