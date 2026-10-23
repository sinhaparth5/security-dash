<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Service;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    /**
     * Every service on the left; the selected one's URLs and tokens on the right.
     */
    public function index(Request $request): Response
    {
        $selected = $request->integer('service') ?: null;

        return Inertia::render('tokens/Index', [
            'services' => Service::query()
                ->withCount('tokens')
                ->orderBy('name')
                ->orderBy('environment')
                ->get()
                ->map(fn (Service $service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'environment' => $service->environment,
                    'status' => $service->status,
                    'tokens_count' => $service->tokens_count,
                ]),
            'selected' => fn () => $selected === null ? null : $this->present(Service::findOrFail($selected)),
        ]);
    }

    /**
     * Issue an extra token, shown once via flash. Existing tokens keep working,
     * so a service can switch over before the old one is revoked.
     */
    public function store(Request $request, Service $service): RedirectResponse
    {
        $days = $request->validate(['expires_in_days' => ['nullable', 'integer', 'in:30,90,180,365']])['expires_in_days'] ?? null;
        $token = $service->createToken('heartbeat', expiresAt: $days ? now()->addDays((int) $days) : null);

        AuditEvent::record('token.issued', $service, ['token_id' => $token->accessToken->id, 'expires_at' => $token->accessToken->expires_at?->toIso8601String()]);

        Inertia::flash('serviceToken', [
            'name' => $service->name,
            'environment' => $service->environment,
            'token' => $token->plainTextToken,
            'existing' => true,
        ]);

        return back();
    }

    /**
     * Revoke one of a service's tokens, e.g. after a leak. Takes effect on its next request.
     */
    public function destroy(Service $service, int $token): RedirectResponse
    {
        abort_unless($service->tokens()->whereKey($token)->delete() > 0, 404);

        AuditEvent::record('token.revoked', $service, ['token_id' => $token]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Token #{$token} revoked."]);

        return back();
    }

    /**
     * Limit where the service's tokens may be used from. An empty list allows any address.
     */
    public function updateAllowedIps(Request $request, Service $service): RedirectResponse
    {
        $request->merge(['allowed_ips' => preg_split('/[\s,]+/', (string) $request->input('allowed_ips'), flags: PREG_SPLIT_NO_EMPTY)]);
        $validated = $request->validate([
            'allowed_ips' => ['array', 'max:50'],
            'allowed_ips.*' => [function (string $attribute, string $value, Closure $fail) {
                if (! self::isIpOrCidr($value)) {
                    $fail("\"{$value}\" is not an IP address or CIDR range.");
                }
            }],
        ]);

        $allowed = array_values(array_unique($validated['allowed_ips']));
        $service->update(['allowed_ips' => $allowed ?: null]);

        AuditEvent::record('service.allowed_ips_updated', $service, ['allowed_ips' => $allowed]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $allowed ? 'IP allowlist saved.' : 'IP allowlist cleared: any address may report.']);

        return back();
    }

    private static function isIpOrCidr(string $value): bool
    {
        [$ip, $mask] = explode('/', $value, 2) + [1 => null];

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $maxMask = str_contains($ip, ':') ? 128 : 32;

        return $mask === null || (ctype_digit($mask) && (int) $mask <= $maxMask);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'environment' => $service->environment,
            'status' => $service->status,
            'last_ping_at' => $service->last_ping_at?->toIso8601String(),
            'allowed_ips' => $service->allowed_ips ?? [],
            'tokens' => $service->tokens()->latest('id')->get()->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ]),
        ];
    }
}
