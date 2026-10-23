<?php

namespace App\Http\Controllers;

use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class DashboardController extends Controller
{
    /**
     * A service that has not reported within this window is flagged stale.
     */
    public const STALE_AFTER_HOURS = 24;

    /**
     * Worst first, matching statusOrder on the frontend.
     */
    private const RANK = [ServiceStatus::Red->value => 0, ServiceStatus::Yellow->value => 1, ServiceStatus::Unknown->value => 2, ServiceStatus::Green->value => 3];

    /**
     * The status matrix, worst services first, linking to each service's detail page.
     */
    public function __invoke(): Response
    {
        // ponytail: whole matrix in one prop, paginate and slim the rows past a few hundred services
        $services = Service::query()
            ->with(self::relations())
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Service $service) => self::RANK[$service->status->value])
            ->values()
            ->map(fn (Service $service) => self::present($service));

        return Inertia::render('Dashboard', [
            'services' => $services,
        ]);
    }

    /**
     * The relations present() reads, for with() or load().
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return ['dependencies' => fn ($query) => $query->orderBy('name'), 'dependencies.advisories', 'tokens' => fn ($query) => $query->latest('id')];
    }

    /**
     * One service with its tokens, dependencies (worst first) and advisories.
     *
     * @return array<string, mixed>
     */
    public static function present(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'environment' => $service->environment,
            'status' => $service->status,
            'last_ping_at' => $service->last_ping_at?->toIso8601String(),
            'tokens' => $service->tokens->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ]),
            'is_stale' => $service->last_ping_at === null || $service->last_ping_at->lt(now()->subHours(self::STALE_AFTER_HOURS)),
            'dependencies' => $service->dependencies
                ->sortBy(fn (Dependency $dependency) => self::RANK[$dependency->status->value])
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
        ];
    }
}
