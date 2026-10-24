<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\EvaluateService;
use App\Models\AuditEvent;
use App\Models\Setting;
use App\Upstream\GitHubApp;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    /** Form field => config key. */
    private const FIELDS = [
        'webhook_url' => 'services.alerts.webhook_url',
        'typesafe_key' => 'services.typesafe.key',
        'typesafe_model' => 'services.typesafe.model',
        'github_pr_token' => 'services.github.pr_token',
    ];

    /**
     * Show where each integration value comes from. Secrets are only ever sent as their last four characters.
     */
    public function edit(Request $request, GitHubApp $app): Response
    {
        $saved = Setting::pluck('value', 'key');
        // The manifest flow sends this back, so only a flow started here can create the app.
        $state = $request->session()->get('github_app_state') ?? tap(Str::random(40), fn (string $state) => $request->session()->put('github_app_state', $state));
        $slug = $app->slug();

        return Inertia::render('settings/Integrations', [
            'integrations' => collect(self::FIELDS)->map(function (string $key) use ($saved) {
                $value = (string) ($saved[$key] ?? config($key));

                return [
                    'source' => $saved->has($key) ? 'saved' : ($value !== '' ? 'env' : null),
                    'value' => match (true) {
                        $value === '' => null,
                        Setting::KEYS[$key] => '••••'.Str::substr($value, -4),
                        default => $value,
                    },
                ];
            }),
            'githubApp' => [
                'enabled' => $app->enabled(),
                'slug' => $slug,
                'install_url' => $slug === null ? null : "https://github.com/apps/{$slug}/installations/new",
                'settings_url' => $slug === null ? null : "https://github.com/settings/apps/{$slug}",
                'create_url' => 'https://github.com/settings/apps/new?state='.$state,
                'manifest' => json_encode(GitHubApp::manifest(), JSON_UNESCAPED_SLASHES),
                'logo_url' => asset('patchwatch-bot.png'),
            ],
        ]);
    }

    /**
     * Where GitHub sends the browser back after the app is created from the manifest: swap the
     * one-time code for the app's credentials and save them.
     */
    public function createGithubApp(Request $request, GitHubApp $app): RedirectResponse
    {
        $state = $request->session()->pull('github_app_state');

        abort_unless(is_string($state) && is_string($request->query('state')) && hash_equals($state, $request->query('state')) && is_string($request->query('code')), 403);

        try {
            $slug = $app->createFromManifest($request->query('code'));
        } catch (RequestException $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('GitHub did not hand over the app. Create it again from here.')]);

            return to_route('integrations.edit');
        }

        AuditEvent::record('settings.github_app_created', details: ['slug' => $slug]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':slug is ready. Install it on your repositories, and upload its logo.', ['slug' => $slug])]);

        return to_route('integrations.edit');
    }

    /**
     * Forget the app's credentials. The app itself stays on GitHub until it's deleted there.
     */
    public function forgetGithubApp(): RedirectResponse
    {
        Setting::destroy(array_values(GitHubApp::KEYS));

        AuditEvent::record('settings.github_app_removed');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('patchwatch-bot is disconnected. Delete it on GitHub too if you no longer need it.')]);

        return to_route('integrations.edit');
    }

    /**
     * Save the filled fields and drop the cleared ones. A blank field keeps what is there.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'webhook_url' => ['nullable', 'url:https', 'max:2048', function (string $attribute, string $value, Closure $fail) {
                // ponytail: rejects IP literals and localhost only; a DNS name pointing at a private address
                // still passes. Resolve and pin the address at send time if operators stop being trusted.
                $host = trim((string) parse_url($value, PHP_URL_HOST), '[]');

                if (filter_var($host, FILTER_VALIDATE_IP) !== false || in_array(strtolower(rtrim($host, '.')), ['localhost', 'localhost.localdomain'], true) || str_ends_with(strtolower($host), '.localhost')) {
                    $fail(__('Use the webhook\'s public hostname, not an IP address or localhost.'));
                }
            }],
            'typesafe_key' => ['nullable', 'string', 'max:500'],
            'typesafe_model' => ['nullable', 'string', 'max:100', 'regex:/^[\w.\-]+$/'],
            'github_pr_token' => ['nullable', 'string', 'max:500'],
            'clear' => ['array'],
            'clear.*' => ['string', 'in:'.implode(',', array_keys(self::FIELDS))],
        ]);

        $changed = [];

        foreach (self::FIELDS as $field => $key) {
            if (filled($validated[$field] ?? null)) {
                Setting::updateOrCreate(['key' => $key], ['value' => trim($validated[$field])]);
                $changed[] = $field;
            } elseif (in_array($field, $validated['clear'] ?? [], true) && Setting::destroy($key) > 0) {
                $changed[] = $field;
            }
        }

        if ($changed !== []) {
            // Names only: the audit log must never hold the secrets themselves.
            AuditEvent::record('settings.updated', details: ['changed' => $changed]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed === [] ? __('Nothing to change.') : __('Integrations saved.')]);

        return to_route('integrations.edit');
    }

    /**
     * Send a test message to the alert webhook.
     */
    public function testWebhook(): RedirectResponse
    {
        $sent = EvaluateService::postWebhook('Patchwatch test alert: this webhook will get a message when a service turns Red.');

        Inertia::flash('toast', $sent
            ? ['type' => 'success', 'message' => __('Test alert sent.')]
            : ['type' => 'error', 'message' => __('The webhook did not accept the test alert. Check the URL.')]);

        return to_route('integrations.edit');
    }
}
