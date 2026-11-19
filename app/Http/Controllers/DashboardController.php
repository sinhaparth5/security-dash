<?php

namespace App\Http\Controllers;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\AdvisoryHistory;
use App\Models\Dependency;
use App\Models\DependencyGraph;
use App\Models\RiskAcceptance;
use App\Models\Service;
use App\Models\ServiceSnapshot;
use App\Models\StatusChange;
use Illuminate\Support\Carbon;
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
            'fixTimes' => fn () => self::fixTimes(),
            'redTime' => fn () => self::redTime(),
            'fixFirst' => fn () => self::fixFirst(),
            'drift' => fn () => self::drift(),
            'coverage' => fn () => self::coverage(),
        ]);
    }

    /**
     * How many services sat in each status at the end of each of the last 30 days, for the fleet and per environment,
     * and this month's status changes for the calendar. Past days come from the nightly snapshots (a service missing a
     * day keeps its previous one); today is the live status.
     *
     * @return array{trend: list<array<string, int|string>>, environments: array<string, list<array<string, int|string>>>, events: list<array{service_id: int, service: string, status: ServiceStatus, at: string}>}
     */
    public static function history(): array
    {
        $services = Service::query()->get(['id', 'name', 'environment', 'status']);
        $environments = $services->pluck('environment', 'id');
        $snapshots = ServiceSnapshot::where('date', '>=', today()->subDays(29)->toDateString())->orderBy('date')->get(['service_id', 'date', 'status'])
            ->groupBy(fn (ServiceSnapshot $snapshot) => $snapshot->date->toDateString());
        $empty = array_fill_keys(array_map(fn (ServiceStatus $status) => $status->value, ServiceStatus::cases()), 0);

        $current = [];
        $trend = [];
        $byEnvironment = [];

        foreach (range(29, 0) as $daysAgo) {
            $date = today()->subDays($daysAgo)->toDateString();
            $current = $daysAgo === 0
                ? $services->mapWithKeys(fn (Service $service) => [$service->id => $service->status])->all()
                : collect($snapshots->get($date, []))->mapWithKeys(fn (ServiceSnapshot $snapshot) => [$snapshot->service_id => $snapshot->status])->all() + $current;
            $day = ['date' => $date] + $empty;
            $perEnvironment = [];

            foreach ($current as $serviceId => $status) {
                if (! $environments->has($serviceId)) {
                    continue; // deleted since
                }

                $environment = (string) $environments[$serviceId];
                $perEnvironment[$environment] ??= ['date' => $date] + $empty;
                $perEnvironment[$environment][$status->value]++;
                $day[$status->value]++;
            }

            $trend[] = $day;

            foreach ($environments->unique() as $environment) {
                $byEnvironment[$environment][] = $perEnvironment[$environment] ?? ['date' => $date] + $empty;
            }
        }

        $names = $services->pluck('name', 'id');
        ksort($byEnvironment);

        return [
            'trend' => $trend,
            'environments' => $byEnvironment,
            'events' => array_values(StatusChange::where('created_at', '>=', now()->startOfMonth())->orderBy('created_at')->orderBy('id')->get()
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
     * How long fixes took over the last 90 days, from first sighting to the evaluation that no longer found the
     * advisory, by severity and by environment, and the share fixed inside its deadline (Advisory::deadlineDays;
     * KEV status isn't kept in the history, so exploited advisories are held to their severity's deadline).
     *
     * @return array{window_days: int, severity: list<array<string, mixed>>, environment: list<array<string, mixed>>, detection: array<string, mixed>}
     */
    public static function fixTimes(): array
    {
        $window = 90;
        $fixes = AdvisoryHistory::query()->join('services', 'services.id', '=', 'advisory_history.service_id')
            ->where('resolved_at', '>=', now()->subDays($window))
            ->get(['advisory_history.osv_id', 'advisory_history.severity', 'advisory_history.first_seen_at', 'advisory_history.resolved_at', 'services.environment'])
            ->map(fn (AdvisoryHistory $fix) => [
                'severity' => match (true) {
                    str_starts_with($fix->osv_id, 'MAL-') => 'MALWARE',
                    $fix->severity === null => 'UNRATED',
                    default => str_replace('MEDIUM', 'MODERATE', strtoupper($fix->severity)),
                },
                'environment' => (string) $fix->getAttribute('environment'),
                'days' => $fix->first_seen_at->diffInSeconds($fix->resolved_at) / 86400,
                'deadline' => Advisory::deadlineDays($fix->osv_id, $fix->severity),
            ]);

        $summarise = fn (Collection $group, string $key) => [
            'label' => $group->first()[$key],
            'fixed' => $group->count(),
            'median_days' => self::percentile($group->pluck('days'), 0.5),
            'p90_days' => self::percentile($group->pluck('days'), 0.9),
            'on_time' => round($group->filter(fn (array $fix) => $fix['days'] <= $fix['deadline'])->count() / $group->count() * 100),
        ];
        // ponytail: a severity label not listed here sorts with the first; add it if OSV starts using one.
        $order = ['MALWARE', 'CRITICAL', 'HIGH', 'MODERATE', 'LOW', 'UNRATED'];

        return [
            'window_days' => $window,
            'severity' => array_values($fixes->groupBy('severity')->map(fn (Collection $group) => $summarise($group, 'severity'))
                ->sortBy(fn (array $row) => array_search($row['label'], $order, true))->all()),
            'environment' => array_values($fixes->groupBy('environment')->map(fn (Collection $group) => $summarise($group, 'environment'))->sortKeys()->all()),
            'detection' => self::detectionDelay(),
        ];
    }

    /**
     * How late the fleet first saw advisories OSV published, over the last 30 days: from OSV's publish date to the
     * first evaluation anywhere that found it. Only advisories published after the service that first saw them
     * started reporting count, so onboarding a service with old advisories doesn't read as slow detection.
     *
     * @return array{window_days: int, seen: int, median_days: float|null, p90_days: float|null}
     */
    public static function detectionDelay(): array
    {
        $window = 30;
        // ponytail: reads every history row OSV dated; bound by published_at if the history grows past ~100k rows.
        $delays = AdvisoryHistory::query()->join('services', 'services.id', '=', 'advisory_history.service_id')
            ->whereNotNull('advisory_history.published_at')
            ->get(['advisory_history.osv_id', 'advisory_history.first_seen_at', 'advisory_history.published_at', 'services.created_at as service_created_at'])
            ->groupBy('osv_id')
            ->map(fn (Collection $rows) => $rows->sortBy('first_seen_at')->firstOrFail())
            ->filter(fn (AdvisoryHistory $first) => $first->first_seen_at->gte(now()->subDays($window))
                && $first->published_at?->gte(Carbon::parse((string) $first->getAttribute('service_created_at'))))
            ->map(fn (AdvisoryHistory $first) => max(0.0, $first->published_at?->diffInSeconds($first->first_seen_at) / 86400))
            ->values();

        return [
            'window_days' => $window,
            'seen' => $delays->count(),
            'median_days' => $delays->isEmpty() ? null : self::percentile($delays, 0.5),
            'p90_days' => $delays->isEmpty() ? null : self::percentile($delays, 0.9),
        ];
    }

    /**
     * How long services sat Red over the last 90 days, replayed from status_changes: days Red, how often they
     * turned Red, and the median time back out. The ten slowest services, plus totals per environment.
     *
     * @return array{window_days: int, services: list<array<string, mixed>>, environment: list<array<string, mixed>>}
     */
    public static function redTime(): array
    {
        $window = 90;
        $start = now()->subDays($window);
        // ponytail: reads every status change: a day-level snapshot can't give recovery times. Bound to changes since the
        // window start (plus each service's last one before it) if status_changes passes ~100k rows.
        $changes = StatusChange::query()->orderBy('created_at')->orderBy('id')->get(['service_id', 'status', 'created_at'])->groupBy('service_id');

        $services = Service::query()->orderBy('name')->get(['id', 'name', 'environment', 'status', 'created_at'])->map(function (Service $service) use ($changes, $start) {
            $timeline = collect($changes->get($service->id, []))->map(fn (StatusChange $change) => [$change->status, $change->created_at])->all();

            // Graded before status history existed: its current status, from registration.
            if ($timeline === [] && $service->created_at !== null) {
                $timeline = [[$service->status, $service->created_at]];
            }

            $spells = [];

            foreach ($timeline as $i => [$status, $from]) {
                $until = $timeline[$i + 1][1] ?? null;

                if ($status === ServiceStatus::Red && ($until === null || $until->gt($start))) {
                    $spells[] = [$from, $until];
                }
            }

            $recoveries = collect($spells)->filter(fn (array $spell) => $spell[1] !== null)->map(fn (array $spell) => $spell[0]->diffInSeconds($spell[1]) / 86400);

            return [
                'id' => $service->id,
                'name' => $service->name,
                'environment' => $service->environment,
                'red_days' => round(collect($spells)->sum(fn (array $spell) => $start->max($spell[0])->diffInSeconds($spell[1] ?? now()) / 86400), 1),
                'times_red' => collect($spells)->filter(fn (array $spell) => $spell[0]->gte($start))->count(),
                'median_recovery_days' => $recoveries->isEmpty() ? null : self::percentile($recoveries, 0.5),
                'red_now' => $spells !== [] && end($spells)[1] === null,
            ];
        })->filter(fn (array $service) => $service['red_days'] > 0);

        return [
            'window_days' => $window,
            'services' => array_values($services->sortByDesc('red_days')->take(10)->all()),
            'environment' => array_values($services->groupBy('environment')->map(fn (Collection $group, string $environment) => [
                'label' => $environment,
                'services' => $group->count(),
                'red_days' => round($group->sum('red_days'), 1),
                'times_red' => $group->sum('times_red'),
            ])->sortKeys()->all()),
        ];
    }

    /**
     * Open advisories in the order to fix them: malware, then known exploited, then by EPSS and severity, scaled down
     * when the usage scan shows the code never reaches it and outside production. Accepted risks are left out.
     * Ordering only: it never changes a grade.
     *
     * @return list<array{service: array{id: int, name: string, environment: string}, package: string, version: string, osv_id: string, fixed_version: string|null, score: float, reasons: list<string>}>
     */
    public static function fixFirst(int $limit = 10): array
    {
        $accepted = RiskAcceptance::active()->get(['service_id', 'osv_id'])->map(fn (RiskAcceptance $acceptance) => $acceptance->service_id.':'.$acceptance->osv_id)->flip();

        // ponytail: scores every open advisory in PHP; move the score into SQL past ~50k advisories.
        return array_values(Advisory::with('dependency.service:id,name,environment')->get()
            ->reject(fn (Advisory $advisory) => $advisory->dependency->service === null
                || $accepted->has($advisory->dependency->service_id.':'.$advisory->osv_id))
            ->map(function (Advisory $advisory) {
                $service = $advisory->dependency->service;
                $production = in_array(strtolower($service->environment), ['production', 'prod'], true);
                $severity = str_replace('MEDIUM', 'MODERATE', strtoupper((string) $advisory->severity));
                $reasons = match (true) {
                    $advisory->isMalicious() => ['malware'],
                    $advisory->known_exploited => ['KEV'],
                    default => array_values(array_filter([
                        $advisory->epss !== null ? 'EPSS '.round($advisory->epss * 100).'%' : null,
                        strtolower($severity ?: 'unrated'),
                    ])),
                };
                // Malware 100 and KEV 50 sit above anything EPSS and severity reach (at most 1 + 20 + 4 = 25).
                $score = match (true) {
                    $advisory->isMalicious() => 100,
                    $advisory->known_exploited => 50,
                    default => 1 + 20 * ($advisory->epss ?? 0) + (['CRITICAL' => 4, 'HIGH' => 3, 'LOW' => 1][$severity] ?? 2),
                };

                // Malware runs at install whatever the code calls, so reachability doesn't discount it.
                if ($advisory->reachable === false && ! $advisory->isMalicious()) {
                    $score *= 0.25;
                    $reasons[] = 'not reached';
                } elseif ($advisory->reachable === true) {
                    $reasons[] = 'reachable';
                }

                if (! $production) {
                    $score *= 0.5;
                }

                $reasons[] = $production ? 'prod' : $service->environment;

                return [
                    'service' => ['id' => $service->id, 'name' => $service->name, 'environment' => $service->environment],
                    'package' => $advisory->dependency->name,
                    'version' => $advisory->dependency->version,
                    'osv_id' => $advisory->osv_id,
                    'fixed_version' => $advisory->fixed_version,
                    'score' => round($score, 2),
                    'reasons' => $reasons,
                ];
            })
            ->sortByDesc('score')->take($limit)->all());
    }

    /**
     * How far each service's dependencies lag their latest release: now, and the change in majors behind since the
     * oldest snapshot in the last 30 days. The ten furthest behind, plus fleet totals. An early warning, never a grade.
     *
     * @return array{since: string|null, totals: array{outdated: int, majors_behind: int, end_of_life: int}, services: list<array<string, mixed>>}
     */
    public static function drift(): array
    {
        $since = ServiceSnapshot::where('date', '>=', today()->subDays(30)->toDateString())->whereNotNull('majors_behind')->min('date');
        $then = $since === null ? collect() : ServiceSnapshot::where('date', $since)->pluck('majors_behind', 'service_id');

        $services = Service::with('dependencies:id,service_id,version,latest_version,eol_at')->orderBy('name')->get(['id', 'name', 'environment'])
            ->map(function (Service $service) use ($then) {
                $drift = ServiceSnapshot::drift($service);

                return ['id' => $service->id, 'name' => $service->name, 'environment' => $service->environment] + $drift
                    + ['majors_change' => $then->has($service->id) ? $drift['majors_behind'] - (int) $then[$service->id] : null];
            });

        return [
            'since' => $since === null ? null : Carbon::parse($since)->toDateString(),
            'totals' => ['outdated' => (int) $services->sum('outdated'), 'majors_behind' => (int) $services->sum('majors_behind'), 'end_of_life' => (int) $services->sum('end_of_life')],
            'services' => array_values($services->filter(fn (array $service) => $service['outdated'] > 0 || $service['end_of_life'] > 0)
                ->sortByDesc(fn (array $service) => [$service['end_of_life'], $service['majors_behind'], $service['outdated']])->take(10)->all()),
        ];
    }

    /**
     * Where the tracker has stopped watching: services silent for a day, services not graded for two hours, services
     * graded Unknown (with why), and risk acceptances expiring in the next 14 days.
     *
     * @return array{services: int, gaps: list<array{kind: string, service: array{id: int, name: string, environment: string}, detail: string}>}
     */
    public static function coverage(): array
    {
        $services = Service::withCount(['dependencies', 'dependencies as untracked_count' => fn ($query) => $query->where('status', ServiceStatus::Unknown)])
            ->orderBy('name')->get();
        $gap = fn (string $kind, Service $service, string $detail) => [
            'kind' => $kind,
            'service' => ['id' => $service->id, 'name' => $service->name, 'environment' => $service->environment],
            'detail' => $detail,
        ];
        $gaps = [];

        foreach ($services as $service) {
            $settled = $service->created_at?->lt(now()->subHours(2)) ?? true;
            $dependencies = (int) $service->getAttribute('dependencies_count');
            $untracked = (int) $service->getAttribute('untracked_count');

            // Watched repositories report on push, so a quiet day there is normal.
            if (! $service->watch_repository && ($service->last_ping_at === null || $service->last_ping_at->lt(now()->subHours(self::STALE_AFTER_HOURS)))) {
                $gaps[] = $gap('silent', $service, $service->last_ping_at === null ? 'has never reported' : 'last reported '.$service->last_ping_at->diffForHumans());
            }

            if ($settled && ($service->evaluated_at === null || $service->evaluated_at->lt(now()->subHours(2)))) {
                $gaps[] = $gap('unevaluated', $service, $service->evaluated_at === null ? 'has never been graded' : 'last graded '.$service->evaluated_at->diffForHumans());
            }

            if ($service->status === ServiceStatus::Unknown) {
                $gaps[] = $gap('unknown', $service, match (true) {
                    $service->evaluated_at === null => 'Unknown: not graded yet',
                    $dependencies === 0 => 'Unknown: no dependencies reported',
                    default => "Unknown: {$untracked} of {$dependencies} dependencies have no advisory source",
                });
            }
        }

        RiskAcceptance::with('service')->whereBetween('expires_at', [now(), now()->addDays(14)])->orderBy('expires_at')->get()
            ->each(function (RiskAcceptance $acceptance) use (&$gaps, $gap) {
                if ($acceptance->service !== null) {
                    $gaps[] = $gap('expiring', $acceptance->service, "risk acceptance for {$acceptance->osv_id} expires on ".$acceptance->expires_at?->toDateString());
                }
            });

        return ['services' => $services->count(), 'gaps' => $gaps];
    }

    /**
     * Nearest-rank percentile, in days to one decimal.
     *
     * @param  Collection<int, float>  $values
     */
    private static function percentile(Collection $values, float $p): float
    {
        $sorted = $values->sort()->values();

        return round($sorted[max(0, (int) ceil($p * $sorted->count()) - 1)], 1);
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
                    'affected_symbols' => $advisory->affected_symbols ?? [],
                    'reachable' => $advisory->reachable,
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
                'supply_chain' => $dependency->supply_chain ?? [],
                'symbols_scanned' => $dependency->linked_symbols !== null,
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
            'watch_repository' => $service->watch_repository,
            'fix_plan_pull_request_url' => $service->fix_plan_pull_request_url,
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
