<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Service;
use App\Models\StatusChange;
use App\Upstream\GitHub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'autoPullRequests' => $service->auto_pull_requests,
            'badgeUrl' => $service->badge_token ? route('badge.show', $service->badge_token) : null,
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
     * Link the service to its GitHub repository ("owner/name"), where version-bump PRs are opened,
     * and choose whether evaluations open them on their own.
     */
    public function updateRepository(Request $request, Service $service): RedirectResponse
    {
        $validated = $request->validate([
            'repository' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/'],
            'auto_pull_requests' => ['boolean'],
        ], [
            'regex' => 'Use the owner/name form, for example acme/billing-api.',
        ]);

        $service->update(['repository' => $validated['repository'] ?? null, 'auto_pull_requests' => $request->boolean('auto_pull_requests')]);

        AuditEvent::record('service.repository_updated', $service, ['repository' => $service->repository, 'auto_pull_requests' => $service->auto_pull_requests]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $service->repository ? "Linked to {$service->repository}." : 'Repository unlinked.']);

        return back();
    }

    /**
     * Create or replace the secret URL of the service's README status badge. The old URL stops working.
     */
    public function rotateBadge(Service $service): RedirectResponse
    {
        $service->update(['badge_token' => Str::random(40)]);

        AuditEvent::record('service.badge_rotated', $service);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'New badge URL created. Update any README that used the old one.']);

        return back();
    }

    /**
     * Delete the service with its dependencies, tokens and history. The request must repeat the
     * service's name, so a stray click can't do it. Audit events stay, keeping the name in their details.
     */
    public function destroy(Request $request, Service $service): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'string', Rule::in([$service->name])]], [
            'confirmation.in' => "Type {$service->name} to confirm.",
        ]);

        DB::transaction(function () use ($service) {
            AuditEvent::record('service.deleted', $service, ['name' => $service->name, 'environment' => $service->environment]);
            // Sanctum's tokens are polymorphic, so no foreign key deletes them.
            $service->tokens()->delete();
            $service->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Deleted {$service->name} ({$service->environment})."]);

        // The service's own page is gone; anywhere else (the repositories list) stays put.
        return url()->previous() === route('services.show', $service) ? to_route('dashboard') : back();
    }
}
