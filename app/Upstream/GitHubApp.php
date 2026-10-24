<?php

namespace App\Upstream;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Patchwatch's own GitHub App, the bot that posts checks and PR comments and opens PRs as itself.
 * It authenticates as the app with a short-lived JWT, then per installation with an hour-long token.
 */
class GitHubApp
{
    /**
     * Where the app's credentials live, by what they are.
     */
    public const KEYS = [
        'id' => 'services.github.app_id',
        'slug' => 'services.github.app_slug',
        'private_key' => 'services.github.app_private_key',
        'webhook_secret' => 'services.github.webhook_secret',
    ];

    public function enabled(): bool
    {
        return filled(Setting::config(self::KEYS['id'])) && filled(Setting::config(self::KEYS['private_key']));
    }

    public function slug(): ?string
    {
        return Setting::config(self::KEYS['slug']) ?: null;
    }

    /**
     * Whether a webhook delivery is signed with the app's webhook secret.
     */
    public function verify(string $payload, ?string $signature): bool
    {
        $secret = (string) Setting::config(self::KEYS['webhook_secret']);

        return $secret !== '' && $signature !== null && hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature);
    }

    /**
     * An installation token that can act on the repository, or null when the app isn't installed on it.
     */
    public function token(string $repository): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $installation = $this->asApp()->get("repos/{$repository}/installation");

        return $installation->notFound() ? null : $this->installationToken((int) $installation->throw()->json('id'));
    }

    /**
     * Every repository the app is installed on.
     * ponytail: first 100 installations and 100 repositories each; paginate if an org installs it more widely.
     *
     * @return list<array{full_name: string, private: bool, default_branch: string, html_url: string}>
     */
    public function repositories(): array
    {
        $repositories = [];

        foreach ($this->asApp()->get('app/installations', ['per_page' => 100])->throw()->json() as $installation) {
            $listed = self::client($this->installationToken((int) $installation['id']))
                ->get('installation/repositories', ['per_page' => 100])->throw()->json('repositories');

            foreach ($listed as $repository) {
                $repositories[] = [
                    'full_name' => (string) $repository['full_name'],
                    'private' => (bool) $repository['private'],
                    'default_branch' => (string) $repository['default_branch'],
                    'html_url' => (string) $repository['html_url'],
                ];
            }
        }

        return $repositories;
    }

    /**
     * The manifest the "Create GitHub App" button posts to GitHub: the bot's name, where it sends
     * webhooks, and the least it needs to post checks, comment on PRs and open fix PRs.
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'name' => 'patchwatch-bot',
            'url' => config('app.url'),
            'description' => 'Patchwatch checks every push and pull request for vulnerable and suspicious dependencies.',
            'hook_attributes' => ['url' => route('github.webhook'), 'active' => true],
            'redirect_url' => route('integrations.github-app.callback'),
            'public' => false,
            'default_permissions' => [
                'checks' => 'write',
                'contents' => 'write',
                'pull_requests' => 'write',
                'metadata' => 'read',
            ],
            'default_events' => ['push', 'pull_request'],
        ];
    }

    /**
     * Finish the manifest flow: swap GitHub's one-time code for the new app's credentials and save them.
     */
    public function createFromManifest(string $code): string
    {
        $app = Http::baseUrl('https://api.github.com/')->acceptJson()->timeout(20)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->post('app-manifests/'.rawurlencode($code).'/conversions')
            ->throw()
            ->json();

        foreach (['id' => $app['id'], 'slug' => $app['slug'], 'private_key' => $app['pem'], 'webhook_secret' => $app['webhook_secret']] as $field => $value) {
            Setting::updateOrCreate(['key' => self::KEYS[$field]], ['value' => (string) $value]);
        }

        return (string) $app['slug'];
    }

    public static function client(string $token): PendingRequest
    {
        return Http::baseUrl('https://api.github.com/')
            ->withToken($token)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * Installation tokens last an hour; they're kept 50 minutes, encrypted like every other secret here.
     */
    private function installationToken(int $installation): string
    {
        $key = "github-app:installation:{$installation}";
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return Crypt::decryptString($cached);
        }

        $token = (string) $this->asApp()->post("app/installations/{$installation}/access_tokens")->throw()->json('token');
        Cache::put($key, Crypt::encryptString($token), now()->addMinutes(50));

        return $token;
    }

    private function asApp(): PendingRequest
    {
        return self::client($this->jwt());
    }

    /**
     * The app's own RS256 JWT: backdated a minute for clock drift, valid for nine (GitHub allows ten).
     */
    private function jwt(): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode(['iat' => now()->subMinute()->timestamp, 'exp' => now()->addMinutes(9)->timestamp, 'iss' => (string) Setting::config(self::KEYS['id'])]);

        if (! openssl_sign($unsigned, $signature, (string) Setting::config(self::KEYS['private_key']), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The GitHub App private key could not sign a token.');
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
