<?php

use App\Jobs\ReviewPullRequest;
use App\Upstream\RepositoryScanner;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    fakeGithubApp();
});

/**
 * @param  array<string, array{0: string, 1?: array<string, mixed>}>  $packages  name => [version, extra fields]
 * @return array{dependencies: array<string, array<string, mixed>>, requires: array<string, string>, usage_scanned: bool}
 */
function scanOf(array $packages): array
{
    return [
        'dependencies' => collect($packages)->mapWithKeys(fn (array $package, string $name) => ["npm|{$name}" => [
            'ecosystem' => 'npm', 'name' => $name, 'version' => $package[0], 'requires' => [], 'usage' => ['sites' => [], 'bin' => false], ...$package[1] ?? [],
        ]])->all(),
        'requires' => collect($packages)->map(fn () => '*')->all(),
        'usage_scanned' => true,
    ];
}

/**
 * @param  list<list<string>>  $vulnsPerQuery
 */
function fakeReview(array $vulnsPerQuery, array $comments = []): void
{
    Http::fake([
        'api.github.com/repos/acme/billing/installation' => Http::response(['id' => 7]),
        'api.github.com/app/installations/7/access_tokens' => Http::response(['token' => 'ghs_bot'], 201),
        'api.github.com/repos/acme/billing/check-runs' => Http::response(['id' => 501], 201),
        'api.github.com/repos/acme/billing/check-runs/501' => Http::response(['id' => 501]),
        'api.github.com/repos/acme/billing/issues/42/comments*' => Http::sequence()->push($comments)->push(['id' => 9], 201),
        'api.github.com/repos/acme/billing/issues/comments/*' => Http::response(['id' => 9]),
        'api.osv.dev/v1/querybatch' => Http::response(['results' => array_map(fn (array $ids) => ['vulns' => array_map(fn (string $id) => ['id' => $id, 'modified' => '2026-10-01T00:00:00Z'], $ids)], $vulnsPerQuery)]),
        'api.osv.dev/v1/vulns/GHSA-lodash' => Http::response(['id' => 'GHSA-lodash', 'summary' => 'Prototype pollution | in merge', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'lodash', 'ecosystem' => 'npm'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '4.17.21']]]]]]]),
        'api.osv.dev/v1/vulns/GHSA-old' => Http::response(['id' => 'GHSA-old',
            'affected' => [['package' => ['name' => 'left-pad', 'ecosystem' => 'npm'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '1.3.0']]]]]]]),
        'api.deps.dev/*' => Http::response(null, 404),
        'registry.npmjs.org/*' => Http::response(null, 404),
    ]);
}

test('a pr that brings in a reachable fixable advisory fails its check and gets a review comment', function () {
    $this->mock(RepositoryScanner::class, function ($scanner) {
        $scanner->shouldReceive('scan')->with('acme/billing', 'base1')->andReturn(scanOf(['left-pad' => ['1.0.0'], 'express' => ['4.21.0']]));
        $scanner->shouldReceive('scan')->with('acme/billing', 'head1')->andReturn(scanOf([
            'left-pad' => ['1.3.0'],
            'express' => ['4.21.0'],
            'lodash' => ['4.17.20', ['usage' => ['sites' => [['file' => 'src/app.ts', 'line' => 3, 'kind' => 'source']], 'bin' => false]]],
            'lodahs' => ['1.0.0'],
        ]));
    });
    // Queried in order: what the PR changes (left-pad, lodash, lodahs), then the versions it replaces (left-pad 1.0.0).
    fakeReview([[], ['GHSA-lodash'], [], ['GHSA-old']]);

    ReviewPullRequest::dispatchSync('acme/billing', 42, 'head1', 'base1');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), 'check-runs/501')
        && $request['conclusion'] === 'failure'
        && $request['output']['title'] === '1 vulnerability to fix before merging');
    Http::assertSent(function ($request) {
        $body = $request['body'] ?? '';

        return $request->method() === 'POST' && str_ends_with($request->url(), 'issues/42/comments')
            && $request->header('Authorization') === ['Bearer ghs_bot']
            && str_starts_with($body, '<!-- patchwatch -->')
            && str_contains($body, 'This PR adds 2, changes 1 and removes 0 packages.')
            && str_contains($body, '| ❌ | `lodash@4.17.20` | [`GHSA-lodash`](https://osv.dev/vulnerability/GHSA-lodash) Prototype pollution \| in merge | HIGH | 4.17.21 | Yes, `src/app.ts:3` |')
            && str_contains($body, '- `lodahs` is one letter away from the popular `lodash`.')
            && str_contains($body, '- `left-pad` 1.0.0 → 1.3.0 clears GHSA-old');
    });
});

test('a pr with no dependency risks passes and leaves no comment', function () {
    $this->mock(RepositoryScanner::class, function ($scanner) {
        $scanner->shouldReceive('scan')->andReturn(scanOf(['express' => ['4.21.0']]));
    });
    fakeReview([]);

    ReviewPullRequest::dispatchSync('acme/billing', 42, 'head1', 'base1');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['conclusion'] === 'success' && $request['output']['title'] === 'No new dependency risks');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'comments'));
});

test('the earlier review comment is updated in place once the risks are gone', function () {
    $this->mock(RepositoryScanner::class, function ($scanner) {
        $scanner->shouldReceive('scan')->andReturn(scanOf(['express' => ['4.21.0']]));
    });
    fakeReview([], [['id' => 9, 'user' => ['type' => 'Bot'], 'body' => "<!-- patchwatch -->\nold review"]]);

    ReviewPullRequest::dispatchSync('acme/billing', 42, 'head1', 'base1');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), 'issues/comments/9')
        && str_contains($request['body'], 'Nothing to flag as of `head1`.'));
});
