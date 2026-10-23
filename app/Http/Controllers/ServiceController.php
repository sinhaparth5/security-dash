<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ServiceController extends Controller
{
    /**
     * Register a service (if new) and issue it a heartbeat token, shown once via flash.
     * Same behaviour as `php artisan service:token`: existing tokens stay valid.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'environment' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
        ], [
            'regex' => 'Use letters, numbers, dots, dashes or underscores.',
        ]);

        $service = Service::firstOrCreate($validated);

        Inertia::flash('serviceToken', [
            'name' => $service->name,
            'environment' => $service->environment,
            'token' => $service->createToken('heartbeat')->plainTextToken,
            'existing' => ! $service->wasRecentlyCreated,
        ]);

        return back();
    }

    /**
     * Revoke one of a service's tokens, e.g. after a leak. Takes effect on its next request.
     */
    public function revokeToken(Service $service, int $token): RedirectResponse
    {
        abort_unless($service->tokens()->whereKey($token)->delete() > 0, 404);

        return back();
    }
}
