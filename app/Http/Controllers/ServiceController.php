<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Service;
use App\Models\StatusChange;
use App\Upstream\GitHub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    /**
     * One service's dependencies grouped by what they need: security fixes, updates, nothing.
     */
    public function show(Service $service, GitHub $github): Response
    {
        return Inertia::render('services/Show', [
            'service' => DashboardController::present($service->load(DashboardController::relations())),
            'canOpenPullRequests' => $github->enabled(),
            'history' => $service->statusChanges()->latest('id')->limit(20)->get()->map(fn (StatusChange $change) => [
                'id' => $change->id,
                'status' => $change->status,
                'created_at' => $change->created_at->toIso8601String(),
            ]),
        ]);
    }

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
        $token = $service->createToken('heartbeat');

        if ($service->wasRecentlyCreated) {
            AuditEvent::record('service.registered', $service);
        }

        AuditEvent::record('token.issued', $service, ['token_id' => $token->accessToken->id]);

        Inertia::flash('serviceToken', [
            'name' => $service->name,
            'environment' => $service->environment,
            'token' => $token->plainTextToken,
            'existing' => ! $service->wasRecentlyCreated,
        ]);

        return back();
    }

    /**
     * Link the service to its GitHub repository ("owner/name"), where version-bump PRs are opened.
     */
    public function updateRepository(Request $request, Service $service): RedirectResponse
    {
        $validated = $request->validate([
            'repository' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/'],
        ], [
            'regex' => 'Use the owner/name form, for example acme/billing-api.',
        ]);

        $service->update(['repository' => $validated['repository'] ?? null]);

        AuditEvent::record('service.repository_updated', $service, ['repository' => $service->repository]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $service->repository ? "Linked to {$service->repository}." : 'Repository unlinked.']);

        return back();
    }
}
