<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\EvaluateService;
use App\Models\AuditEvent;
use App\Models\Setting;
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
    public function edit(): Response
    {
        $saved = Setting::pluck('value', 'key');

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
        ]);
    }

    /**
     * Save the filled fields and drop the cleared ones. A blank field keeps what is there.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'webhook_url' => ['nullable', 'url:https', 'max:2048'],
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
