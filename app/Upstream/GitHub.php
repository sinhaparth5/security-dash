<?php

namespace App\Upstream;

use App\Enums\Ecosystem;
use App\Models\Advisory;
use App\Models\AuditEvent;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;

/**
 * Opens a pull request that bumps one dependency in the service's repository.
 */
class GitHub
{
    public const MANIFESTS = [Ecosystem::Npm->value => 'package.json', Ecosystem::Go->value => 'go.mod'];

    public function enabled(): bool
    {
        return filled(Setting::config('services.github.pr_token'));
    }

    /**
     * Open a PR bumping the dependency to the lowest version that clears every advisory still counted
     * against it, store its URL and audit it. Returns the version it bumps to.
     *
     * @throws RuntimeException when no fix is known or the manifest cannot be changed
     * @throws RequestException when GitHub refuses
     */
    public function openUpgrade(Service $service, Dependency $dependency, bool $automatic = false): string
    {
        $accepted = $service->riskAcceptances()->active()->pluck('osv_id');
        $advisories = $dependency->advisories->reject(fn (Advisory $advisory) => $accepted->contains($advisory->osv_id));
        $version = self::fixVersion($advisories->pluck('fixed_version')->all())
            ?? throw new RuntimeException('No fixed version is known for these advisories yet.');

        $url = $this->openVersionBump((string) $service->repository, $dependency, $version, array_values($advisories->pluck('osv_id')->all()));

        $dependency->update(['pull_request_url' => $url, 'pull_request_state' => 'open']);
        AuditEvent::record('pull_request.opened', $service, ['dependency' => $dependency->name, 'version' => $version, 'url' => $url, 'automatic' => $automatic ?: null]);

        return $version;
    }

    /**
     * The highest of the advisories' fixed versions: the lowest version that clears them all.
     *
     * @param  array<int, string|null>  $versions
     */
    public static function fixVersion(array $versions): ?string
    {
        return collect($versions)->filter()
            ->sort(fn (string $a, string $b) => version_compare(Releases::normalize($b), Releases::normalize($a)))
            ->first();
    }

    /**
     * Whether a PR this app opened is open, merged or closed without merging.
     */
    public function pullRequestState(string $url): ?string
    {
        if (! preg_match('#^https://github\.com/([^/]+/[^/]+)/pull/(\d+)$#', $url, $match)) {
            return null;
        }

        $pull = $this->api()->get("repos/{$match[1]}/pulls/{$match[2]}")->throw()->json();

        return match (true) {
            ($pull['merged_at'] ?? null) !== null => 'merged',
            $pull['state'] === 'open' => 'open',
            default => 'closed',
        };
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

        [$path, $file, $updated, $overridden, $refresh] = $this->findManifest($api, $repository, $head, $manifest, $dependency, $version)
            ?? throw new RuntimeException("Found no {$manifest} in {$repository} to change for {$dependency->name}.");

        $branch = 'patchwatch/'.preg_replace('/[^A-Za-z0-9._-]+/', '-', ltrim($dependency->name, '@')).'-'.$version;

        try {
            $api->post("repos/{$repository}/git/refs", ['ref' => "refs/heads/{$branch}", 'sha' => $head])->throw();
        } catch (RequestException $e) {
            throw $e->response->status() === 422 ? new RuntimeException("Branch {$branch} already exists: a PR for this upgrade is probably open.") : $e;
        }

        $title = ($overridden ? 'Override' : 'Bump')." {$dependency->name} from {$dependency->version} to {$version}";

        $api->put("repos/{$repository}/contents/{$path}", [
            'message' => $title,
            'content' => base64_encode($updated),
            'sha' => $file['sha'],
            'branch' => $branch,
        ])->throw();

        return $api->post("repos/{$repository}/pulls", [
            'title' => $title,
            'head' => $branch,
            'base' => $base,
            'body' => implode("\n", [
                "Patchwatch flagged `{$dependency->name}@{$dependency->version}` in this service. This ".($overridden ? "pins the transitive dependency with an override in `{$path}`" : 'bumps it').", to `{$version}`, which fixes:",
                '',
                ...array_map(fn (string $id) => "- {$id}", $advisoryIds),
                '',
                "Only `{$path}` is changed. Run {$refresh} on this branch and commit the result so the lockfile matches.",
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
     * The package.json with an override pinning a transitive dependency for this package manager,
     * or null when the manifest is not a JSON object.
     */
    public static function override(string $manifest, string $tool, string $name, string $version): ?string
    {
        $data = json_decode($manifest);

        if (! $data instanceof stdClass) {
            return null;
        }

        // pnpm reads pnpm.overrides, yarn reads resolutions, npm and bun read overrides.
        $pnpm = $tool === 'pnpm' ? $data->pnpm ??= new stdClass : null;
        $target = match ($tool) {
            'pnpm' => $pnpm->overrides ??= new stdClass,
            'yarn' => $data->resolutions ??= new stdClass,
            default => $data->overrides ??= new stdClass,
        };
        $target->{$name} = '^'.Releases::normalize($version);

        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $indent = preg_match('/^\{\R([ \t]+)"/', $manifest, $m) ? $m[1] : '  ';
        $json = (string) preg_replace_callback('/^(?: {4})+/m', fn (array $spaces) => str_repeat($indent, intdiv(strlen($spaces[0]), 4)), $json);

        return $json.(str_ends_with($manifest, "\n") ? "\n" : '');
    }

    /**
     * The first manifest, shallowest first, that lists the dependency directly. Failing that, an npm
     * dependency is transitive, so the shallowest package.json gets an override instead.
     *
     * @return array{string, array{sha: string, content: string}, string, bool, string}|null
     */
    private function findManifest(PendingRequest $api, string $repository, string $head, string $manifest, Dependency $dependency, string $version): ?array
    {
        // ponytail: one recursive tree listing, first 20 candidates; GitHub truncates trees past ~100k entries.
        $blobs = $api->get("repos/{$repository}/git/trees/{$head}", ['recursive' => 1])->throw()->collect('tree')
            ->where('type', 'blob')
            ->pluck('path')
            ->reject(fn (string $path) => preg_match('#(^|/)(node_modules|vendor)/#', $path) === 1);
        $paths = $blobs->filter(fn (string $path) => basename($path) === $manifest)
            ->sortBy(fn (string $path) => substr_count($path, '/'))
            ->take(20);
        $npm = $dependency->ecosystem === Ecosystem::Npm;
        $tool = function (string $path) use ($blobs) {
            $dir = dirname($path) === '.' ? '' : dirname($path).'/';

            return match (true) {
                $blobs->contains($dir.'pnpm-lock.yaml') => 'pnpm',
                $blobs->contains($dir.'yarn.lock') => 'yarn',
                $blobs->contains($dir.'bun.lock'), $blobs->contains($dir.'bun.lockb') => 'bun',
                default => 'npm',
            };
        };
        $refresh = fn (string $path) => '`'.($npm ? $tool($path).' install' : 'go mod tidy').'`'.(dirname($path) === '.' ? '' : ' in `'.dirname($path).'`');
        $files = [];

        foreach ($paths as $path) {
            $file = $files[$path] = $api->get("repos/{$repository}/contents/{$path}", ['ref' => $head])->throw()->json();
            $updated = self::bump($dependency->ecosystem, base64_decode($file['content']), $dependency->name, $version);

            if ($updated !== null) {
                return [$path, $file, $updated, false, $refresh($path)];
            }
        }

        // ponytail: Go transitive deps already sit in go.mod as // indirect, so only npm needs an override.
        $root = $paths->first();

        if (! $npm || $root === null) {
            return null;
        }

        $updated = self::override(base64_decode($files[$root]['content']), $tool($root), $dependency->name, $version);

        return $updated === null ? null : [$root, $files[$root], $updated, true, $refresh($root)];
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com/')
            ->withToken((string) Setting::config('services.github.pr_token'))
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->acceptJson()
            ->timeout(20);
    }
}
