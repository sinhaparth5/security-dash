<?php

use App\Upstream\RepositoryScanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.github.pr_token' => 'ghp-test']);
});

/**
 * Serve a GitHub-style tarball (everything under one top-level folder) built from the given files.
 * A value of ['link' => target] makes a symlink.
 *
 * @param  array<string, string|array{link: string}>  $files
 */
function fakeTarball(array $files): void
{
    $root = sys_get_temp_dir().'/patchwatch-test-'.Str::random(8);

    foreach ($files as $path => $content) {
        File::ensureDirectoryExists(dirname("{$root}/acme-billing-abc/{$path}"));
        is_array($content) ? symlink($content['link'], "{$root}/acme-billing-abc/{$path}") : File::put("{$root}/acme-billing-abc/{$path}", $content);
    }

    Process::path($root)->run(['tar', '-czf', 'repo.tar.gz', 'acme-billing-abc'])->throw();
    Http::fake(['api.github.com/repos/acme/billing/tarball/abc' => Http::response(File::get("{$root}/repo.tar.gz"))]);
    File::deleteDirectory($root);
}

test('a repository is read from its lockfile, its imports and its go.mod', function () {
    fakeTarball([
        'package.json' => json_encode(['dependencies' => ['lodash' => '^4.17.0'], 'devDependencies' => ['vitest' => '^2.0.0']]),
        'package-lock.json' => json_encode(['lockfileVersion' => 3, 'packages' => [
            '' => ['dependencies' => ['lodash' => '^4.17.0']],
            'node_modules/lodash' => ['version' => '4.17.20'],
            'node_modules/vitest' => ['version' => '2.0.0', 'dev' => true],
        ]]),
        'src/app.js' => "import merge from 'lodash/merge';\n",
        'go.mod' => "module acme\n\ngo 1.24\n\nrequire (\n\tgolang.org/x/net v0.17.0\n\tgithub.com/acme/fork v1.0.0 // indirect\n\tgithub.com/acme/local v0.1.0\n)\n\nreplace github.com/acme/fork => github.com/acme/fork-patched v1.0.1\nreplace github.com/acme/local => ../local\n",
    ]);

    $scan = app(RepositoryScanner::class)->scan('acme/billing', 'abc');

    expect(array_keys($scan['dependencies']))->toBe(['npm|lodash', 'go|golang.org/x/net', 'go|github.com/acme/fork-patched'])
        ->and($scan['dependencies']['npm|lodash'])->toMatchArray(['version' => '4.17.20', 'usage' => ['sites' => [['file' => 'src/app.js', 'line' => 1, 'kind' => 'source']], 'bin' => false]])
        ->and($scan['dependencies']['go|github.com/acme/fork-patched']['version'])->toBe('v1.0.1')
        ->and($scan['requires'])->toBe(['lodash' => '^4.17.0'])
        ->and($scan['usage_scanned'])->toBeTrue();
});

test('a symlinked lockfile is never followed out of the checkout', function () {
    $outside = tempnam(sys_get_temp_dir(), 'secret');
    File::put($outside, json_encode(['lockfileVersion' => 3, 'packages' => ['node_modules/stolen' => ['version' => '1.0.0']]]));
    fakeTarball(['package.json' => '{}', 'package-lock.json' => ['link' => $outside]]);

    expect(fn () => app(RepositoryScanner::class)->scan('acme/billing', 'abc'))->toThrow(RuntimeException::class, 'Found no lockfile');

    unlink($outside);
});
