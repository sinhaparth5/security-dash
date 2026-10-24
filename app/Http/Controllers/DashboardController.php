<?php

namespace App\Http\Controllers;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\DependencyGraph;
use App\Models\RiskAcceptance;
use App\Models\Service;
use App\Models\StatusChange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

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
            ->map(fn (Service $service) => self::present($service))
            ->sortBy(fn (array $service) => [self::RANK[$service['status']->value], ! $service['malicious']])
            ->values();

        return Inertia::render('Dashboard', [
            'services' => $services,
            'pipeline' => fn () => self::pipeline(),
            'history' => fn () => self::history(),
        ]);
    }

    /**
     * How many services sat in each status at the end of each of the last 30 days,
     * and this month's status changes for the calendar.
     *
     * @return array{trend: list<array<string, int|string>>, events: list<array{service_id: int, service: string, status: ServiceStatus, at: string}>}
     */
    public static function history(): array
    {
        // ponytail: replays every status change ever; bound it to a snapshot per day once the table grows past ~100k rows
        $changes = StatusChange::query()->orderBy('created_at')->orderBy('id')->get(['service_id', 'status', 'created_at'])->groupBy('service_id');
        $services = Service::query()->get(['id', 'name', 'status', 'created_at']);

        $trend = [];

        foreach (range(29, 0) as $daysAgo) {
            $endOfDay = now()->subDays($daysAgo)->endOfDay();
            $day = ['date' => $endOfDay->toDateString()] + array_fill_keys(array_map(fn (ServiceStatus $status) => $status->value, ServiceStatus::cases()), 0);

            foreach ($services as $service) {
                $timeline = $changes->get($service->id, new Collection);
                $change = $timeline->last(fn (StatusChange $change) => $change->created_at->lte($endOfDay));
                // Services graded before status history existed have no rows: count them from when they were registered.
                $status = $change instanceof StatusChange
                    ? $change->status
                    : ($timeline->isEmpty() && $service->created_at?->lte($endOfDay) ? $service->status : null);

                if ($status !== null) {
                    $day[$status->value]++;
                }
            }

            $trend[] = $day;
        }

        $names = $services->pluck('name', 'id');

        return [
            'trend' => $trend,
            'events' => array_values($changes->flatten()
                ->filter(fn (StatusChange $change) => $change->created_at->gte(now()->startOfMonth()))
                ->map(fn (StatusChange $change) => [
                    'service_id' => $change->service_id,
                    'service' => (string) $names->get($change->service_id),
                    'status' => $change->status,
                    'at' => $change->created_at->toIso8601String(),
                ])
                ->all()),
        ];
    }

    /**
     * Is grading still happening? Evaluations run at least hourly, so a gap of two hours
     * means the queue worker or the scheduler is down.
     *
     * @return array{last_evaluated_at: string|null, stalled: bool, failed_jobs: int}
     */
    public static function pipeline(): array
    {
        $lastEvaluatedAt = Service::max('evaluated_at');
        $expectsEvaluations = Service::where('created_at', '<', now()->subHours(2))->exists();

        return [
            'last_evaluated_at' => $lastEvaluatedAt === null ? null : now()->parse($lastEvaluatedAt)->toIso8601String(),
            'stalled' => $expectsEvaluations && ($lastEvaluatedAt === null || now()->parse($lastEvaluatedAt)->lt(now()->subHours(2))),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * The relations present() reads, for with() or load().
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return [
            'dependencies' => fn ($query) => $query->orderBy('name'),
            'dependencies.advisories',
            'riskAcceptances' => fn ($query) => $query->with('user:id,name')->orderBy('osv_id'),
        ];
    }

    /**
     * One service with its dependencies (worst first) and advisories.
     *
     * @return array<string, mixed>
     */
    public static function present(Service $service): array
    {
        $accepted = $service->riskAcceptances->filter(fn (RiskAcceptance $acceptance) => $acceptance->isActive())->keyBy('osv_id');
        $graph = DependencyGraph::of($service, $service->dependencies);

        $dependencies = $service->dependencies->map(function (Dependency $dependency) use ($accepted, $graph) {
            $advisories = $dependency->advisories
                ->sortBy(fn (Advisory $advisory) => [! $advisory->isMalicious(), ! $advisory->known_exploited, -($advisory->epss ?? 0)])
                ->values()
                ->map(fn (Advisory $advisory) => [
                    'id' => $advisory->id,
                    'osv_id' => $advisory->osv_id,
                    'aliases' => $advisory->aliases,
                    'severity' => $advisory->severity,
                    'summary' => $advisory->summary,
                    'fixed_version' => $advisory->fixed_version,
                    'affected_packages' => $advisory->affected_packages ?? [],
                    'malicious' => $advisory->isMalicious(),
                    'known_exploited' => $advisory->known_exploited,
                    'epss' => $advisory->epss,
                    'accepted' => $accepted->has($advisory->osv_id),
                    'triage' => $advisory->triage,
                    'due_at' => $advisory->dueAt()->toIso8601String(),
                    'overdue' => $advisory->dueAt()->isPast() && ! $accepted->has($advisory->osv_id),
                ]);

            return [
                'id' => $dependency->id,
                'ecosystem' => $dependency->ecosystem,
                'name' => $dependency->name,
                'version' => $dependency->version,
                'latest_version' => $dependency->latest_version,
                'status' => $dependency->status,
                'eol_at' => $dependency->eol_at?->toDateString(),
                'pull_request_url' => $dependency->pull_request_url,
                'pull_request_state' => $dependency->pull_request_state,
                'lookalike' => $dependency->lookalike(),
                'direct' => $dependency->ecosystem === Ecosystem::Npm && $graph->known() ? $graph->isDirect($dependency->name) : null,
                'usage' => $graph->usage($dependency),
                'import_sites' => $dependency->usage['sites'] ?? [],
                // Only findings need their route in; paths for every package would bloat the dashboard.
                'reachable' => $advisories->isEmpty() ? null : $graph->reachable($dependency),
                'paths' => $advisories->isEmpty() ? [] : array_slice($graph->paths($dependency->name), 0, 3),
                'path_count' => $advisories->isEmpty() ? 0 : count($graph->paths($dependency->name)),
                'malicious' => $advisories->contains(fn (array $advisory) => $advisory['malicious'] && ! $advisory['accepted']),
                'advisories' => $advisories,
            ];
        });

        return [
            'id' => $service->id,
            'name' => $service->name,
            'environment' => $service->environment,
            'repository' => $service->repository,
            'usage_scanned_at' => $service->usage_scanned_at?->toIso8601String(),
            'fix_plan' => $service->fix_plan ?? [],
            'status' => $service->status,
            'status_changed_at' => $service->status_changed_at?->toIso8601String(),
            'evaluated_at' => $service->evaluated_at?->toIso8601String(),
            'last_ping_at' => $service->last_ping_at?->toIso8601String(),
            'is_stale' => $service->last_ping_at === null || $service->last_ping_at->lt(now()->subHours(self::STALE_AFTER_HOURS)),
            'malicious' => $dependencies->contains('malicious', true),
            'overdue' => $dependencies->sum(fn (array $dependency) => $dependency['advisories']->where('overdue', true)->count()),
            // Worst first; within a status, malware, then actively exploited, then by name.
            'dependencies' => $dependencies
                ->sortBy(fn (array $dependency) => [
                    self::RANK[$dependency['status']->value],
                    ! $dependency['malicious'],
                    ! $dependency['advisories']->contains('known_exploited', true),
                    $dependency['name'],
                ])
                ->values(),
            'risk_acceptances' => $service->riskAcceptances->map(fn (RiskAcceptance $acceptance) => [
                'id' => $acceptance->id,
                'osv_id' => $acceptance->osv_id,
                'reason' => $acceptance->reason,
                'expires_at' => $acceptance->expires_at?->toDateString(),
                'active' => $acceptance->isActive(),
                'accepted_by' => $acceptance->user?->name,
                'created_at' => $acceptance->created_at?->toIso8601String(),
            ]),
        ];
    }
}
