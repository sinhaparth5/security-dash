<?php

use App\Models\AuditEvent;
use App\Models\Setting;
use App\Models\User;
use App\Upstream\GitHub;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('saved integrations win over the environment, are stored encrypted and only show their last four characters', function () {
    config(['services.typesafe.key' => 'ts-from-env', 'services.github.pr_token' => null]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('integrations.update'), [
            'webhook_url' => 'https://hooks.slack.com/services/T/B/secret1234',
            'github_pr_token' => 'github_pat_abcd9876',
            'typesafe_model' => 'jev-1',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('integrations.edit'));

    expect(Setting::config('services.github.pr_token'))->toBe('github_pat_abcd9876')
        ->and(app(GitHub::class)->enabled())->toBeTrue()
        ->and(Setting::config('services.typesafe.key'))->toBe('ts-from-env')
        ->and(DB::table('settings')->where('key', 'services.github.pr_token')->value('value'))->not->toContain('abcd9876')
        ->and(AuditEvent::sole()->details)->toBe(['changed' => ['webhook_url', 'typesafe_model', 'github_pr_token']]);

    $this->actingAs($user)->get(route('integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Integrations')
            ->where('integrations.github_pr_token', ['source' => 'saved', 'value' => '••••9876'])
            ->where('integrations.webhook_url', ['source' => 'saved', 'value' => '••••1234'])
            ->where('integrations.typesafe_key', ['source' => 'env', 'value' => '••••-env'])
            ->where('integrations.typesafe_model', ['source' => 'saved', 'value' => 'jev-1'])
        );
});

test('a blank field keeps the saved value and clearing falls back to the environment', function () {
    config(['services.github.pr_token' => 'from-env']);
    Setting::create(['key' => 'services.github.pr_token', 'value' => 'saved-token']);
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('integrations.update'), ['github_pr_token' => ''])->assertSessionHasNoErrors();
    expect(Setting::config('services.github.pr_token'))->toBe('saved-token')
        ->and(AuditEvent::count())->toBe(0);

    $this->actingAs($user)->put(route('integrations.update'), ['clear' => ['github_pr_token']]);
    expect(Setting::config('services.github.pr_token'))->toBe('from-env');
});

test('the alert webhook must be https and the model a plain name', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('integrations.update'), ['webhook_url' => 'http://example.com/hook', 'typesafe_model' => 'jev latest; rm', 'clear' => ['app.key']])
        ->assertSessionHasErrors(['webhook_url', 'typesafe_model', 'clear.0']);

    expect(Setting::count())->toBe(0);
});

test('the alert webhook cannot point at an ip address or localhost', function (string $url) {
    $this->actingAs(User::factory()->create())
        ->put(route('integrations.update'), ['webhook_url' => $url])
        ->assertSessionHasErrors('webhook_url');
})->with(['https://169.254.169.254/latest', 'https://10.0.0.5/hook', 'https://[::1]/hook', 'https://localhost/hook', 'https://api.localhost/hook']);

test('the test button posts to the saved webhook and reports a rejection', function () {
    Http::preventStrayRequests();
    Setting::create(['key' => 'services.alerts.webhook_url', 'value' => 'https://discord.com/api/webhooks/1/x']);
    Http::fakeSequence('discord.com/*')->push('', 204)->push('', 404);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('integrations.webhook-test'))->assertInertiaFlash('toast.type', 'success');
    $this->actingAs($user)->post(route('integrations.webhook-test'))->assertInertiaFlash('toast.type', 'error');

    Http::assertSent(fn ($request) => $request['allowed_mentions'] === ['parse' => []]);
});

test('guests cannot read or change integrations', function () {
    $this->get(route('integrations.edit'))->assertRedirect(route('login'));
    $this->put(route('integrations.update'), ['github_pr_token' => 'x'])->assertRedirect(route('login'));
    expect(Setting::count())->toBe(0);
});
