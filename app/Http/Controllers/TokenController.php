<?php

namespace App\Http\Controllers;

use App\Models\Service;
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
    public function store(Service $service): RedirectResponse
    {
        Inertia::flash('serviceToken', [
            'name' => $service->name,
            'environment' => $service->environment,
            'token' => $service->createToken('heartbeat')->plainTextToken,
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

        return back();
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
            'tokens' => $service->tokens()->latest('id')->get()->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ]),
        ];
    }
}
