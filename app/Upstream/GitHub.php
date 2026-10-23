<?php

namespace App\Upstream;

use App\Enums\Ecosystem;
use App\Models\Dependency;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Opens a pull request that bumps one dependency in the service's repository.
 */
class GitHub
{
    public const MANIFESTS = [Ecosystem::Npm->value => 'package.json', Ecosystem::Go->value => 'go.mod'];

    public function enabled(): bool
    {
        return filled(config('services.github.pr_token'));
    }

    /**
     * Branch off the default branch, rewrite the manifest, and open the PR. Returns its URL.
     *
     * @param  list<string>  $advisoryIds
     */
    public function openVersionBump(string $repository, Dependency $dependency, string $version, array $advisoryIds): string
    {
        $manifest = self::MANIFESTS[$dependency->ecosystem->value] ?? throw new RuntimeException('Only npm and Go dependencies can be bumped.');
        $api = $this->api();

        $base = $api->get("repos/{$repository}")->throw()->json('default_branch');
        $head = $api->get("repos/{$repository}/git/ref/heads/{$base}")->throw()->json('object.sha');

        [$path, $file, $updated] = $this->findManifest($api, $repository, $head, $manifest, $dependency, $version)
            ?? throw new RuntimeException("{$dependency->name} is not a direct dependency in any {$manifest} in {$repository}, so there is nothing to bump.");

        $branch = 'patchwatch/'.preg_replace('/[^A-Za-z0-9._-]+/', '-', ltrim($dependency->name, '@')).'-'.$version;

        try {
            $api->post("repos/{$repository}/git/refs", ['ref' => "refs/heads/{$branch}", 'sha' => $head])->throw();
        } catch (RequestException $e) {
            throw $e->response->status() === 422 ? new RuntimeException("Branch {$branch} already exists: a PR for this upgrade is probably open.") : $e;
        }

        $title = "Bump {$dependency->name} from {$dependency->version} to {$version}";

        $api->put("repos/{$repository}/contents/{$path}", [
            'message' => $title,
            'content' => base64_encode($updated),
            'sha' => $file['sha'],
            'branch' => $branch,
        ])->throw();

        $refresh = $dependency->ecosystem === Ecosystem::Npm ? 'npm install' : 'go mod tidy';

        return $api->post("repos/{$repository}/pulls", [
            'title' => $title,
            'head' => $branch,
            'base' => $base,
            'body' => implode("\n", [
                "Patchwatch flagged `{$dependency->name}@{$dependency->version}` in this service. This bumps it to `{$version}`, which fixes:",
                '',
                ...array_map(fn (string $id) => "- {$id}", $advisoryIds),
                '',
                "Only `{$path}` is changed. Run `{$refresh}` on this branch and commit the result so the lockfile matches.",
            ]),
        ])->throw()->json('html_url');
    }

    /**
     * The manifest with the dependency pinned to $version, keeping npm's ^ or ~ range prefix,
     * or null when the manifest does not list it directly.
     */
    public static function bump(Ecosystem $ecosystem, string $manifest, string $name, string $version): ?string
    {
        $quoted = preg_quote($name, '/');

        [$pattern, $replacement] = match ($ecosystem) {
            Ecosystem::Npm => ['/("'.$quoted.'"\s*:\s*")([\^~]?)\d[^"]*(")/', '${1}${2}'.Releases::normalize($version).'${3}'],
            Ecosystem::Go => ['/^(\s*(?:require\s+)?'.$quoted.'\s+)v\S+/m', '${1}v'.Releases::normalize($version)],
            default => [null, null],
        };

        if ($pattern === null) {
            return null;
        }

        $updated = preg_replace($pattern, $replacement, $manifest, 1, $count);

        return $count === 1 ? $updated : null;
    }

    /**
     * The first manifest, shallowest first, that lists the dependency directly: [path, file, updated content].
     *
     * @return array{string, array{sha: string, content: string}, string}|null
     */
    private function findManifest(PendingRequest $api, string $repository, string $head, string $manifest, Dependency $dependency, string $version): ?array
    {
        // ponytail: one recursive tree listing, first 20 candidates; GitHub truncates trees past ~100k entries.
        $paths = $api->get("repos/{$repository}/git/trees/{$head}", ['recursive' => 1])->throw()->collect('tree')
            ->where('type', 'blob')
            ->pluck('path')
            ->filter(fn (string $path) => basename($path) === $manifest && ! preg_match('#(^|/)(node_modules|vendor)/#', $path))
            ->sortBy(fn (string $path) => substr_count($path, '/'))
            ->take(20);

        foreach ($paths as $path) {
            $file = $api->get("repos/{$repository}/contents/{$path}", ['ref' => $head])->throw()->json();
            $updated = self::bump($dependency->ecosystem, base64_decode($file['content']), $dependency->name, $version);

            if ($updated !== null) {
                return [$path, $file, $updated];
            }
        }

        return null;
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com/')
            ->withToken((string) config('services.github.pr_token'))
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->acceptJson()
            ->timeout(20);
    }
}
