<?php

namespace App\Upstream;

use App\Enums\Ecosystem;
use App\Http\Requests\Api\V1\HeartbeatRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Reads a repository's dependencies at a commit, the way a reporter would from inside it: npm, pnpm,
 * Yarn and Bun lockfiles through the Node reporter itself (with its import scan, so reachability works
 * without one), and go.mod for Go modules.
 */
class RepositoryScanner
{
    /**
     * Largest source tarball worth downloading.
     */
    private const MAX_BYTES = 200 * 1024 * 1024;

    private const LOCKFILES = ['package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock'];

    public function __construct(private GitHub $github) {}

    /**
     * The dependencies at a commit keyed like a heartbeat manifest, the npm roots, and whether imports were scanned.
     * The Go runtime is left out: go.mod only names the oldest Go it builds with, not the one that built it.
     *
     * @return array{dependencies: array<string, array{ecosystem: string, name: string, version: string, requires?: array<string, string|null>|null, usage?: array{sites: list<array{file: string, line: int, kind: 'source'|'tooling'}>, bin: bool}|null, linked_symbols?: list<string>|null}>, requires: array<string, string|null>|null, usage_scanned: bool}
     *
     * @throws RuntimeException when the repository can't be downloaded or read
     */
    public function scan(string $repository, string $sha): array
    {
        $directory = sys_get_temp_dir().'/patchwatch-scan-'.Str::random(16);
        $source = $directory.'/source';
        File::ensureDirectoryExists($source);

        try {
            $this->github->downloadTarball($repository, $sha, $directory.'/source.tar.gz', self::MAX_BYTES);
            // ponytail: the compressed size is capped, not the extracted one; extract into a size-limited volume if tar bombs become a concern.
            Process::timeout(60)->run(['tar', '-xzf', $directory.'/source.tar.gz', '-C', $source, '--strip-components=1', '--no-same-owner', '--no-same-permissions'])->throw();
            // A symlink could point the parsers at files outside the checkout, such as this app's .env.
            Process::timeout(30)->run(['find', $source, '-type', 'l', '-delete'])->throw();

            $npm = $this->npm($source);
            $go = $this->go($source);

            if ($npm === null && $go === []) {
                throw new RuntimeException('Found no lockfile (package-lock.json, pnpm-lock.yaml, yarn.lock, bun.lock) or go.mod to read.');
            }

            return [
                'dependencies' => [...$npm['dependencies'] ?? [], ...$go],
                'requires' => $npm['requires'] ?? null,
                'usage_scanned' => $npm['usage_scanned'] ?? false,
            ];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * The shallowest JavaScript lockfile, read by the Node reporter in --dry-run mode.
     *
     * @return array{dependencies: array<string, array{ecosystem: string, name: string, version: string, requires?: array<string, string|null>|null, usage?: array{sites: list<array{file: string, line: int, kind: 'source'|'tooling'}>, bin: bool}|null, linked_symbols?: list<string>|null}>, requires: array<string, string|null>|null, usage_scanned: bool}|null
     */
    private function npm(string $source): ?array
    {
        $lockfile = $this->shallowest($source, self::LOCKFILES);

        if ($lockfile === null) {
            return null;
        }

        $relative = Str::after($lockfile, $source.'/');
        // A clean environment: the parser never needs this app's secrets.
        $result = Process::path(dirname($lockfile))->timeout(60)->run([
            'env', '-i', 'PATH='.getenv('PATH'), 'PATCHWATCH_LOCKFILE='.$lockfile, 'PATCHWATCH_SOURCE='.dirname($lockfile),
            'node', base_path('templates/node/patchwatch.mjs'), '--dry-run',
        ]);

        $report = json_decode($result->output(), true);

        if (! $result->successful() || ! is_array($report)) {
            throw new RuntimeException("Couldn't read {$relative}. Check that it's a valid lockfile.");
        }

        // The reporter adds the Node that ran it, which is this server's, not the repository's.
        $report['dependencies'] = array_values(array_filter((array) ($report['dependencies'] ?? []), fn (mixed $dependency) => is_array($dependency) && ($dependency['ecosystem'] ?? null) === Ecosystem::Npm->value));

        $request = new HeartbeatRequest;
        $request->setValidator(Validator::make($report, $request->rules()));

        try {
            return ['dependencies' => $request->manifest(), 'requires' => $request->rootRequires(), 'usage_scanned' => $request->usageScanned()];
        } catch (ValidationException) {
            throw new RuntimeException("{$relative} lists more packages than Patchwatch reads, or names it can't use.");
        }
    }

    /**
     * Every module the shallowest go.mod requires, with replace directives applied as the build would.
     * Go 1.17+ lists indirect dependencies there too.
     *
     * @return array<string, array{ecosystem: string, name: string, version: string}>
     */
    private function go(string $source): array
    {
        $path = $this->shallowest($source, ['go.mod']);

        if ($path === null) {
            return [];
        }

        $mod = (string) preg_replace('#//.*$#m', '', File::get($path));
        $requires = [];
        $replaces = [];

        // Directives come one per line ("require a v1") or in blocks ("require (\n a v1\n)").
        foreach (['require', 'replace'] as $directive) {
            preg_match_all('/^'.$directive.'\s*\(\s*$(.*?)^\s*\)|^'.$directive.'\s+(\S[^\n]*)$/ms', $mod, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                foreach (preg_split('/\R/', ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')) ?: [] as $line) {
                    $parts = preg_split('/\s+/', trim($line)) ?: [];

                    if ($directive === 'require' && count($parts) === 2) {
                        $requires[$parts[0]] = $parts[1];
                    } elseif ($directive === 'replace' && ($arrow = array_search('=>', $parts, true)) !== false) {
                        // A replacement without a version is a local directory: nothing to look up.
                        $replaces[$parts[0]] = isset($parts[$arrow + 2]) ? [$parts[$arrow + 1], $parts[$arrow + 2]] : null;
                    }
                }
            }
        }

        $modules = [];

        foreach ($requires as $name => $version) {
            if (array_key_exists($name, $replaces) && $replaces[$name] === null) {
                continue;
            }

            [$name, $version] = $replaces[$name] ?? [$name, $version];

            if (strlen($name) <= 255 && strlen($version) <= 100) {
                $modules[Ecosystem::Go->value.'|'.$name] = ['ecosystem' => Ecosystem::Go->value, 'name' => $name, 'version' => $version];
            }
        }

        return $modules;
    }

    /**
     * The file with one of these names nearest the repository root, outside dependency and build folders.
     *
     * @param  list<string>  $names
     */
    private function shallowest(string $source, array $names): ?string
    {
        $found = iterator_to_array(Finder::create()->files()->in($source)->name($names)->depth('< 4')
            ->exclude(['node_modules', 'vendor', '.git', 'dist', 'build'])->ignoreDotFiles(false)->ignoreVCS(true), false);

        usort($found, fn ($a, $b) => [substr_count($a->getRelativePathname(), '/'), array_search($a->getFilename(), $names, true)]
            <=> [substr_count($b->getRelativePathname(), '/'), array_search($b->getFilename(), $names, true)]);

        return isset($found[0]) ? $found[0]->getPathname() : null;
    }
}
