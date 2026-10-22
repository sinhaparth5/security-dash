<?php

namespace App\Http\Controllers;

use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * A service that has not reported within this window is flagged stale.
     */
    public const STALE_AFTER_HOURS = 24;

    /**
     * The status matrix, worst services first, with everything the advisory drawer needs.
     */
    public function __invoke(): Response
    {
        $rank = [ServiceStatus::Red->value => 0, ServiceStatus::Yellow->value => 1, ServiceStatus::Unknown->value => 2, ServiceStatus::Green->value => 3];

        // ponytail: whole matrix in one prop, paginate or lazy-load the drawer past a few hundred services
        $services = Service::query()
            ->with(['dependencies' => fn ($query) => $query->orderBy('name'), 'dependencies.advisories'])
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Service $service) => $rank[$service->status->value])
            ->values()
            ->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'environment' => $service->environment,
                'status' => $service->status,
                'last_ping_at' => $service->last_ping_at?->toIso8601String(),
                'is_stale' => $service->last_ping_at === null || $service->last_ping_at->lt(now()->subHours(self::STALE_AFTER_HOURS)),
                'dependencies' => $service->dependencies
                    ->sortBy(fn (Dependency $dependency) => $rank[$dependency->status->value])
                    ->values()
                    ->map(fn (Dependency $dependency) => [
                        'id' => $dependency->id,
                        'ecosystem' => $dependency->ecosystem,
                        'name' => $dependency->name,
                        'version' => $dependency->version,
                        'latest_version' => $dependency->latest_version,
                        'status' => $dependency->status,
                        'advisories' => $dependency->advisories->map(fn (Advisory $advisory) => [
                            'id' => $advisory->id,
                            'osv_id' => $advisory->osv_id,
                            'aliases' => $advisory->aliases,
                            'severity' => $advisory->severity,
                            'summary' => $advisory->summary,
                            'fixed_version' => $advisory->fixed_version,
                        ]),
                    ]),
            ]);

        return Inertia::render('Dashboard', [
            'services' => $services,
        ]);
    }
}
