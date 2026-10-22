<?php

namespace App\Upstream;

use App\Enums\Ecosystem;
use App\Models\Dependency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
            Ecosystem::Npm => $this->packageLatest('https://registry.npmjs.org/'.str_replace('/', '%2F', $dependency->name).'/latest', 'version'),
            Ecosystem::Go => $this->packageLatest('https://proxy.golang.org/'.$this->escapeModulePath($dependency->name).'/@latest', 'Version'),
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
        return Cache::remember('upstream:go-releases', now()->addHours(6), fn () => collect(
            Http::acceptJson()->timeout(15)->retry(2, 500)->get('https://go.dev/dl/?mode=json')->throw()->json()
        )->where('stable', true)->map(fn (array $release) => self::normalize($release['version']))->values()->all());
    }

    /**
     * @return list<array{version: string, security: bool}>
     */
    private function nodeReleases(): array
    {
        return Cache::remember('upstream:node-releases', now()->addHours(6), fn () => collect(
            Http::acceptJson()->timeout(15)->retry(2, 500)->get('https://nodejs.org/dist/index.json')->throw()->json()
        )->map(fn (array $release) => [
            'version' => self::normalize($release['version']),
            'security' => (bool) $release['security'],
        ])->values()->all());
    }

    private function packageLatest(string $url, string $field): ?string
    {
        return Cache::remember('upstream:latest:'.sha1($url), now()->addHours(6), function () use ($url, $field) {
            $response = Http::acceptJson()->timeout(15)->get($url);

            if ($response->notFound() || $response->status() === 410) {
                return null;
            }

            return self::normalize($response->throw()->json($field));
        });
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
