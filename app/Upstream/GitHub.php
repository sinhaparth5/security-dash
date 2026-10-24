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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

/**
 * Talks to the service's repository: opens upgrade PRs, posts check runs and PR comments, and
 * downloads the source for scanning. Acts as the patchwatch-bot app where it's installed, else with
 * the configured token.
 */
class GitHub
{
    public const MANIFESTS = [Ecosystem::Npm->value => 'package.json', Ecosystem::Go->value => 'go.mod'];

    /**
     * Marks the comment the bot keeps up to date on each PR.
     */
    public const COMMENT_MARKER = '<!-- patchwatch -->';

    public function __construct(private GitHubApp $app) {}

    public function enabled(): bool
    {
        return filled(Setting::config('services.github.pr_token')) || $this->app->enabled();
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

        $pull = $this->api($match[1])->get("repos/{$match[1]}/pulls/{$match[2]}")->throw()->json();

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
        $api = $this->api($repository);
        [$base, $head] = $this->branchPoint($api, $repository);

        [$path, $file, $updated, $overridden, $refresh] = $this->findManifest($api, $repository, $head, $manifest, $dependency, $version)
            ?? throw new RuntimeException("Found no {$manifest} in {$repository} to change for {$dependency->name}.");

        $title = ($overridden ? 'Override' : 'Bump')." {$dependency->name} from {$dependency->version} to {$version}";

        return $this->commitAndOpen($api, $repository, $base, $head, 'patchwatch/'.preg_replace('/[^A-Za-z0-9._-]+/', '-', ltrim($dependency->name, '@')).'-'.$version, $path, $file['sha'], $updated, $title, implode("\n", [
            "Patchwatch flagged `{$dependency->name}@{$dependency->version}` in this service. This ".($overridden ? "pins the transitive dependency with an override in `{$path}`" : 'bumps it').", to `{$version}`, which fixes:",
            '',
            ...array_map(fn (string $id) => "- {$id}", $advisoryIds),
            '',
            "Only `{$path}` is changed. Run {$refresh} on this branch and commit the result so the lockfile matches.",
        ]));
    }

    /**
     * Open one PR that applies the service's whole upgrade plan to its root package.json: bumps and
     * overrides in one commit, lockfile refreshes as instructions. Stores its URL and audits it.
     *
     * @throws RuntimeException when the plan has nothing a manifest change can do
     * @throws RequestException when GitHub refuses
     */
    public function openFixPlan(Service $service): string
    {
        $repository = (string) $service->repository;
        $plan = $service->fix_plan ?? [];
        $changes = array_values(array_filter($plan, fn (array $step) => $step['action'] !== 'refresh'));
        $refreshes = array_values(array_filter($plan, fn (array $step) => $step['action'] === 'refresh'));

        if ($changes === []) {
            throw new RuntimeException('The plan only needs lockfile refreshes, which a PR can\'t make: run the commands it shows.');
        }

        $api = $this->api($repository);
        [$base, $head] = $this->branchPoint($api, $repository);
        $blobs = $this->blobs($api, $repository, $head);
        $path = $blobs->filter(fn (string $path) => basename($path) === 'package.json')->sortBy(fn (string $path) => substr_count($path, '/'))->first()
            ?? throw new RuntimeException("Found no package.json in {$repository}.");
        $file = $api->get("repos/{$repository}/contents/{$path}", ['ref' => $head])->throw()->json();
        $manifest = base64_decode($file['content']);
        $tool = self::tool($blobs, $path);
        $applied = [];
        $skipped = [];

        foreach ($changes as $step) {
            $updated = $step['action'] === 'bump'
                ? self::bump(Ecosystem::Npm, $manifest, $step['package'], $step['to'])
                : self::override($manifest, $tool, $step['package'], $step['to']);

            if ($updated === null) {
                $skipped[] = $step;
            } else {
                $manifest = $updated;
                $applied[] = $step;
            }
        }

        if ($applied === []) {
            throw new RuntimeException("None of the plan's changes apply to {$path}: its dependencies aren't listed there.");
        }

        $describe = fn (array $step) => ($step['action'] === 'bump'
            ? "- Bump `{$step['package']}` from {$step['from']} to {$step['to']}"
            : "- Override `{$step['package']}` to `^{$step['to']}` (pulled in via ".implode(' → ', $step['via']).')')
            .($step['breaking'] ? ' **(major: expect breaking changes)**' : '').', fixing '.implode(', ', $step['fixes']);
        $directory = dirname($path) === '.' ? '' : ' in `'.dirname($path).'`';

        $url = $this->commitAndOpen($api, $repository, $base, $head, 'patchwatch/fix-plan-'.substr(sha1((string) json_encode($applied)), 0, 8), $path, $file['sha'], $manifest,
            'Apply the Patchwatch upgrade plan ('.count($applied).' '.Str::plural('change', count($applied)).')',
            implode("\n", array_filter([
                'Patchwatch worked these out by resolving each dependency chain against the npm registry, the way a fresh install would:',
                '',
                ...array_map($describe, $applied),
                $refreshes === [] ? null : "\nThe rest only needs the lockfile refreshed: `npm update ".implode(' ', array_unique(array_merge(...array_map(fn (array $step) => $step['via'], $refreshes)))).'`.',
                $skipped === [] ? null : "\nLeft out because `{$path}` doesn't list them: ".implode(', ', array_map(fn (array $step) => "`{$step['package']}`", $skipped)).'.',
                '',
                "Only `{$path}` is changed. Run `{$tool} install`{$directory} on this branch and commit the lockfile.",
            ], fn (?string $line) => $line !== null)));

        $service->update(['fix_plan_pull_request_url' => $url]);
        AuditEvent::record('pull_request.opened', $service, ['plan' => true, 'url' => $url, 'changes' => count($applied)]);

        return $url;
    }

    /**
     * Create or update a check run on a commit. Returns its id.
     *
     * @param  array<string, mixed>  $fields
     */
    public function checkRun(string $repository, string $sha, array $fields, ?int $id = null): int
    {
        $api = $this->api($repository);

        return (int) ($id === null
            ? $api->post("repos/{$repository}/check-runs", ['name' => 'Patchwatch', 'head_sha' => $sha, ...$fields])
            : $api->patch("repos/{$repository}/check-runs/{$id}", $fields))->throw()->json('id');
    }

    /**
     * Keep one bot comment per PR: update the one it left before, or add one. With a null body,
     * only an existing comment is updated (to say all is clear); none is added.
     */
    public function comment(string $repository, int $number, ?string $body, string $clear): void
    {
        $api = $this->api($repository);
        // ponytail: first 100 comments; a PR with more that also lost the bot's comment gets a second one.
        $existing = $api->get("repos/{$repository}/issues/{$number}/comments", ['per_page' => 100])->throw()->collect()
            ->first(fn (array $comment) => ($comment['user']['type'] ?? null) === 'Bot' && str_contains((string) ($comment['body'] ?? ''), self::COMMENT_MARKER));

        if ($existing !== null) {
            $api->patch("repos/{$repository}/issues/comments/{$existing['id']}", ['body' => self::COMMENT_MARKER."\n".($body ?? $clear)])->throw();
        } elseif ($body !== null) {
            $api->post("repos/{$repository}/issues/{$number}/comments", ['body' => self::COMMENT_MARKER."\n".$body])->throw();
        }
    }

    /**
     * Download the repository at a commit as a gzipped tarball, refusing archives over $limit bytes.
     */
    public function downloadTarball(string $repository, string $sha, string $path, int $limit): void
    {
        $this->api($repository)->timeout(120)->sink($path)
            ->withOptions(['progress' => function (int $total, int $downloaded) use ($limit) {
                if ($total > $limit || $downloaded > $limit) {
                    throw new RuntimeException('The repository is too large to scan.');
                }
            }])
            ->get("repos/{$repository}/tarball/{$sha}")
            ->throw();
    }

    /**
     * The commit the repository's default branch points at.
     */
    public function head(string $repository): string
    {
        return $this->branchPoint($this->api($repository), $repository)[1];
    }

    /**
     * The repository's default branch and the commit it points at.
     *
     * @return array{string, string}
     */
    private function branchPoint(PendingRequest $api, string $repository): array
    {
        $base = (string) $api->get("repos/{$repository}")->throw()->json('default_branch');

        return [$base, (string) $api->get("repos/{$repository}/git/ref/heads/{$base}")->throw()->json('object.sha')];
    }

    /**
     * Branch off $head, commit the new file content, and open the PR. Returns its URL.
     */
    private function commitAndOpen(PendingRequest $api, string $repository, string $base, string $head, string $branch, string $path, string $fileSha, string $content, string $title, string $body): string
    {
        try {
            $api->post("repos/{$repository}/git/refs", ['ref' => "refs/heads/{$branch}", 'sha' => $head])->throw();
        } catch (RequestException $e) {
            throw $e->response->status() === 422 ? new RuntimeException("Branch {$branch} already exists: a PR for this upgrade is probably open.") : $e;
        }

        $api->put("repos/{$repository}/contents/{$path}", [
            'message' => $title,
            'content' => base64_encode($content),
            'sha' => $fileSha,
            'branch' => $branch,
        ])->throw();

        return (string) $api->post("repos/{$repository}/pulls", ['title' => $title, 'head' => $branch, 'base' => $base, 'body' => $body])->throw()->json('html_url');
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
        $blobs = $this->blobs($api, $repository, $head);
        // ponytail: first 20 candidates.
        $paths = $blobs->filter(fn (string $path) => basename($path) === $manifest)
            ->sortBy(fn (string $path) => substr_count($path, '/'))
            ->take(20);
        $npm = $dependency->ecosystem === Ecosystem::Npm;
        $tool = fn (string $path) => self::tool($blobs, $path);
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

    /**
     * Every file in the repository at a commit, outside node_modules and vendor.
     * ponytail: one recursive tree listing; GitHub truncates trees past ~100k entries.
     *
     * @return Collection<int, string>
     */
    private function blobs(PendingRequest $api, string $repository, string $head): Collection
    {
        return $api->get("repos/{$repository}/git/trees/{$head}", ['recursive' => 1])->throw()->collect('tree')
            ->where('type', 'blob')
            ->pluck('path')
            ->reject(fn (string $path) => preg_match('#(^|/)(node_modules|vendor)/#', $path) === 1)
            ->values();
    }

    /**
     * The package manager a package.json is installed with, from the lockfile beside it.
     *
     * @param  Collection<int, string>  $blobs
     */
    private static function tool(Collection $blobs, string $path): string
    {
        $dir = dirname($path) === '.' ? '' : dirname($path).'/';

        return match (true) {
            $blobs->contains($dir.'pnpm-lock.yaml') => 'pnpm',
            $blobs->contains($dir.'yarn.lock') => 'yarn',
            $blobs->contains($dir.'bun.lock'), $blobs->contains($dir.'bun.lockb') => 'bun',
            default => 'npm',
        };
    }

    /**
     * Acts as patchwatch-bot when the app is installed on the repository, else with the configured token.
     */
    private function api(string $repository): PendingRequest
    {
        $token = $this->app->token($repository) ?? (string) Setting::config('services.github.pr_token');

        if ($token === '') {
            throw new RuntimeException("Install patchwatch-bot on {$repository}, or set a GitHub token in Settings, so Patchwatch can reach it.");
        }

        return GitHubApp::client($token);
    }
}
