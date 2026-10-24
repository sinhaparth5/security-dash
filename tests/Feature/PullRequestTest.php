<?php

use App\Enums\Ecosystem;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;
use App\Upstream\GitHub;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.github.pr_token' => 'ghp-test']);
});

function vulnerableLodash(): Dependency
{
    $service = Service::factory()->create(['repository' => 'acme/billing']);
    $dependency = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20']);
    Advisory::factory()->for($dependency)->create(['osv_id' => 'GHSA-1', 'fixed_version' => '4.17.21']);
    Advisory::factory()->for($dependency)->create(['osv_id' => 'GHSA-2', 'fixed_version' => '4.17.9']);

    return $dependency;
}

test('the manifest bump keeps the range prefix and only touches the named dependency', function () {
    $json = "{\n  \"dependencies\": {\n    \"lodash\": \"^4.17.20\",\n    \"lodash.merge\": \"4.6.0\"\n  }\n}\n";
    expect(GitHub::bump(Ecosystem::Npm, $json, 'lodash', '4.17.21'))->toBe(str_replace('^4.17.20', '^4.17.21', $json));

    $mod = "module acme\n\nrequire (\n\tgolang.org/x/net v0.17.0\n\tgolang.org/x/text v0.3.0 // indirect\n)\n";
    expect(GitHub::bump(Ecosystem::Go, $mod, 'golang.org/x/net', '0.23.0'))->toBe(str_replace('v0.17.0', 'v0.23.0', $mod));

    expect(GitHub::bump(Ecosystem::Npm, $json, 'express', '5.0.0'))->toBeNull();
    expect(GitHub::bump(Ecosystem::Npm, '{"dependencies":{"lodash":"github:lodash/lodash"}}', 'lodash', '4.17.21'))->toBeNull();
});

test('a transitive npm dependency is pinned with the override field its package manager reads', function () {
    $json = "{\n\t\"name\": \"frontend\",\n\t\"scripts\": {},\n\t\"dependencies\": {\n\t\t\"@sveltejs/kit\": \"^2.63.0\"\n\t}\n}\n";

    expect(GitHub::override($json, 'pnpm', 'devalue', '5.9.1'))
        ->toBe("{\n\t\"name\": \"frontend\",\n\t\"scripts\": {},\n\t\"dependencies\": {\n\t\t\"@sveltejs/kit\": \"^2.63.0\"\n\t},\n\t\"pnpm\": {\n\t\t\"overrides\": {\n\t\t\t\"devalue\": \"^5.9.1\"\n\t\t}\n\t}\n}\n");
    expect(json_decode(GitHub::override($json, 'yarn', 'devalue', '5.9.1'), true)['resolutions'])->toBe(['devalue' => '^5.9.1']);
    expect(json_decode(GitHub::override('{"overrides":{"semver":"^7.5.2"}}', 'npm', 'devalue', 'v5.9.1'), true)['overrides'])
        ->toBe(['semver' => '^7.5.2', 'devalue' => '^5.9.1']);
    expect(GitHub::override('not json', 'npm', 'devalue', '5.9.1'))->toBeNull();
});

test('a transitive dependency gets an override pr in the nested package.json', function () {
    $dependency = vulnerableLodash();
    Http::fake([
        'api.github.com/repos/acme/billing' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/billing/git/ref/heads/main' => Http::response(['object' => ['sha' => 'abc']]),
        'api.github.com/repos/acme/billing/git/trees/abc*' => Http::response(['tree' => [
            ['path' => 'frontend/package.json', 'type' => 'blob'],
            ['path' => 'frontend/pnpm-lock.yaml', 'type' => 'blob'],
        ]]),
        'api.github.com/repos/acme/billing/contents/frontend/package.json*' => Http::sequence()
            ->push(['sha' => 'file1', 'content' => base64_encode('{"dependencies":{"@sveltejs/kit":"^2.63.0"}}')])
            ->push(['content' => []]),
        'api.github.com/repos/acme/billing/git/refs' => Http::response([], 201),
        'api.github.com/repos/acme/billing/pulls' => Http::response(['html_url' => 'https://github.com/acme/billing/pull/8'], 201),
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('services.dependencies.pull-request.store', [$dependency->service, $dependency]))
        ->assertInertiaFlash('toast.type', 'success');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && json_decode(base64_decode($request['content']), true)['pnpm']['overrides'] === ['lodash' => '^4.17.21']);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pulls')
        && str_starts_with($request['title'], 'Override lodash')
        && str_contains($request['body'], '`pnpm install` in `frontend`'));
});

test('opening a pr bumps the manifest wherever it lives to the version that clears every advisory', function () {
    $dependency = vulnerableLodash();
    Http::fake([
        'api.github.com/repos/acme/billing' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/billing/git/ref/heads/main' => Http::response(['object' => ['sha' => 'abc']]),
        'api.github.com/repos/acme/billing/git/trees/abc*' => Http::response(['tree' => [
            ['path' => 'README.md', 'type' => 'blob'],
            ['path' => 'node_modules/lodash/package.json', 'type' => 'blob'],
            ['path' => 'apps/web/package.json', 'type' => 'blob'],
        ]]),
        'api.github.com/repos/acme/billing/contents/apps/web/package.json*' => Http::sequence()
            ->push(['sha' => 'file1', 'content' => base64_encode('{"dependencies":{"lodash":"^4.17.20"}}')])
            ->push(['content' => []]),
        'api.github.com/repos/acme/billing/git/refs' => Http::response([], 201),
        'api.github.com/repos/acme/billing/pulls' => Http::response(['html_url' => 'https://github.com/acme/billing/pull/7'], 201),
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('services.dependencies.pull-request.store', [$dependency->service, $dependency]))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    expect($dependency->fresh()->pull_request_url)->toBe('https://github.com/acme/billing/pull/7');
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && base64_decode($request['content']) === '{"dependencies":{"lodash":"^4.17.21"}}'
        && $request['branch'] === 'patchwatch/lodash-4.17.21'
        && str_ends_with($request->url(), 'contents/apps/web/package.json'));
    $this->assertDatabaseHas('audit_events', ['action' => 'pull_request.opened']);
});

test('github errors come back as a toast, not a crash', function () {
    $dependency = vulnerableLodash();
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    $this->actingAs(User::factory()->create())
        ->post(route('services.dependencies.pull-request.store', [$dependency->service, $dependency]))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect($dependency->fresh()->pull_request_url)->toBeNull();
});

test('prs need a token and a linked repository', function () {
    $dependency = vulnerableLodash();
    $user = User::factory()->create();

    config(['services.github.pr_token' => null]);
    $this->actingAs($user)->post(route('services.dependencies.pull-request.store', [$dependency->service, $dependency]))->assertNotFound();

    config(['services.github.pr_token' => 'ghp-test']);
    $dependency->service->update(['repository' => null]);
    $this->actingAs($user)->post(route('services.dependencies.pull-request.store', [$dependency->service, $dependency]))->assertNotFound();
});

test('a dependency of another service is a 404', function () {
    $dependency = vulnerableLodash();

    $this->actingAs(User::factory()->create())
        ->post(route('services.dependencies.pull-request.store', [Service::factory()->create(['repository' => 'acme/x']), $dependency]))
        ->assertNotFound();
});

test('linking a repository validates owner/name and is audited', function () {
    $service = Service::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('services.repository.update', $service), ['repository' => 'not a repo'])->assertSessionHasErrors('repository');
    $this->actingAs($user)->put(route('services.repository.update', $service), ['repository' => 'acme/billing-api'])->assertRedirect();

    expect($service->fresh()->repository)->toBe('acme/billing-api');
    $this->assertDatabaseHas('audit_events', ['action' => 'service.repository_updated']);
});

/**
 * A service whose upgrade plan bumps astro, overrides nanoid under it and refreshes postcss.
 */
function plannedRepository(): Service
{
    return Service::factory()->create(['repository' => 'acme/billing', 'fix_plan' => [
        ['action' => 'refresh', 'package' => 'postcss', 'from' => '8.4.31', 'to' => '8.4.49', 'breaking' => false, 'fixes' => ['GHSA-p'], 'via' => ['vite', 'postcss']],
        ['action' => 'bump', 'package' => 'astro', 'from' => '4.16.0', 'to' => '5.0.0', 'breaking' => true, 'fixes' => ['GHSA-a'], 'via' => ['astro']],
        ['action' => 'override', 'package' => 'nanoid', 'from' => '3.3.6', 'to' => '3.3.8', 'breaking' => false, 'fixes' => ['GHSA-n'], 'via' => ['astro', 'vite', 'nanoid']],
    ]]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function fakeRepository(array $extra = []): void
{
    Http::fake([
        ...$extra,
        'api.github.com/repos/acme/billing' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/billing/git/ref/heads/main' => Http::response(['object' => ['sha' => 'abc']]),
        'api.github.com/repos/acme/billing/git/trees/abc*' => Http::response(['tree' => [
            ['path' => 'package.json', 'type' => 'blob'],
            ['path' => 'package-lock.json', 'type' => 'blob'],
            ['path' => 'docs/package.json', 'type' => 'blob'],
        ]]),
        'api.github.com/repos/acme/billing/contents/package.json*' => Http::sequence()
            ->push(['sha' => 'file1', 'content' => base64_encode("{\n  \"dependencies\": {\n    \"astro\": \"^4.16.0\"\n  }\n}\n")])
            ->push(['content' => []]),
        'api.github.com/repos/acme/billing/git/refs' => Http::response([], 201),
        'api.github.com/repos/acme/billing/pulls' => Http::response(['html_url' => 'https://github.com/acme/billing/pull/12'], 201),
    ]);
}

test('one pr applies the whole upgrade plan to the root package.json', function () {
    $service = plannedRepository();
    fakeRepository();

    $this->actingAs(User::factory()->create())
        ->post(route('services.fix-plan.pull-request.store', $service))
        ->assertInertiaFlash('toast.type', 'success');

    expect($service->fresh()->fix_plan_pull_request_url)->toBe('https://github.com/acme/billing/pull/12');
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), 'contents/package.json')
        && json_decode(base64_decode($request['content']), true) === ['dependencies' => ['astro' => '^5.0.0'], 'overrides' => ['nanoid' => '^3.3.8']]);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pulls')
        && $request['title'] === 'Apply the Patchwatch upgrade plan (2 changes)'
        && str_contains($request['body'], 'Bump `astro` from 4.16.0 to 5.0.0 **(major: expect breaking changes)**, fixing GHSA-a')
        && str_contains($request['body'], '`npm update vite postcss`')
        && str_contains($request['body'], 'Run `npm install` on this branch'));
    $this->assertDatabaseHas('audit_events', ['action' => 'pull_request.opened', 'service_id' => $service->id]);
});

test('a plan of lockfile refreshes only has nothing to put in a pr', function () {
    $service = Service::factory()->create(['repository' => 'acme/billing', 'fix_plan' => [
        ['action' => 'refresh', 'package' => 'postcss', 'from' => '8.4.31', 'to' => '8.4.49', 'breaking' => false, 'fixes' => ['GHSA-p'], 'via' => ['postcss']],
    ]]);

    $this->actingAs(User::factory()->create())
        ->post(route('services.fix-plan.pull-request.store', $service))
        ->assertInertiaFlash('toast.type', 'error');

    Http::assertNothingSent();
});

test('where patchwatch-bot is installed, prs are opened as the bot with an installation token', function () {
    $publicKey = fakeGithubApp();
    config(['services.github.pr_token' => null]);
    $service = plannedRepository();
    fakeRepository([
        'api.github.com/repos/acme/billing/installation' => Http::response(['id' => 77]),
        'api.github.com/app/installations/77/access_tokens' => Http::response(['token' => 'ghs_bot'], 201),
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('services.fix-plan.pull-request.store', $service))
        ->assertInertiaFlash('toast.type', 'success');

    Http::assertSent(function ($request) use ($publicKey) {
        if (! str_ends_with($request->url(), '/installation')) {
            return false;
        }

        [$header, $claims, $signature] = explode('.', substr($request->header('Authorization')[0], 7));
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));

        return openssl_verify("{$header}.{$claims}", $decode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1
            && json_decode($decode($claims), true)['iss'] === '12345';
    });
    // GitHub rejects a JSON body of [] here.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/access_tokens') && $request->body() === '');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pulls') && $request->header('Authorization') === ['Bearer ghs_bot']);
});
