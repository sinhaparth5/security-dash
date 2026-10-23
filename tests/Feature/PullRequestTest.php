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
