<?php

namespace App\Http\Controllers;

use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\RiskAcceptance;
use App\Upstream\Releases;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    /**
     * Which services run a package, and on which versions. Without a search, the most widely used packages.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q'));

        // ponytail: top 50 by service count; paginate if people browse past the most shared packages.
        $packages = Dependency::query()
            ->selectRaw('ecosystem, name, count(*) as services_count')
            ->when($search !== '', fn ($query) => $query->whereLike('name', '%'.addcslashes($search, '%_\\').'%'))
            ->groupBy('ecosystem', 'name')
            ->orderByDesc('services_count')
            ->orderBy('name')
            ->limit(50)
            ->get();

        $rows = Dependency::query()
            ->with('service:id,name,environment')
            ->whereIn('name', $packages->pluck('name'))
            ->get()
            ->groupBy(fn (Dependency $dependency) => $dependency->ecosystem->value.'|'.$dependency->name);

        return Inertia::render('packages/Index', [
            'search' => $search,
            'packages' => $packages->map(fn (Dependency $package) => $this->present($rows[$package->ecosystem->value.'|'.$package->name] ?? collect()))->values(),
            'upgrades' => fn () => self::upgrades(),
        ]);
    }

    /**
     * The package upgrades that clear the most across the fleet: open fixable advisories grouped by package, the
     * target being the highest fixed version any of them needs, weighted by severity per service. Accepted risks
     * are left out.
     *
     * @return list<array{ecosystem: string, package: string, to: string, advisories: int, weight: int, services: list<array{id: int, name: string, environment: string, from: string}>}>
     */
    public static function upgrades(int $limit = 10): array
    {
        $accepted = RiskAcceptance::active()->get(['service_id', 'osv_id'])->map(fn (RiskAcceptance $acceptance) => $acceptance->service_id.':'.$acceptance->osv_id)->flip();
        $weight = fn (?string $severity) => ['CRITICAL' => 4, 'HIGH' => 3, 'LOW' => 1][strtoupper((string) $severity)] ?? 2;

        // ponytail: direct upgrades only; a transitive npm package may need its parent bumped (the service's fix_plan says which).
        return array_values(Advisory::with('dependency.service:id,name,environment')->whereNotNull('fixed_version')->get()
            ->reject(fn (Advisory $advisory) => $advisory->dependency->service === null
                || $accepted->has($advisory->dependency->service_id.':'.$advisory->osv_id))
            ->groupBy(fn (Advisory $advisory) => $advisory->dependency->ecosystem->value."\0".$advisory->dependency->name)
            ->map(fn (Collection $advisories) => [
                'ecosystem' => $advisories->first()->dependency->ecosystem->value,
                'package' => $advisories->first()->dependency->name,
                'to' => (string) $advisories->pluck('fixed_version')->sort(fn (string $a, string $b) => version_compare(Releases::normalize($b), Releases::normalize($a)))->first(),
                'advisories' => $advisories->pluck('osv_id')->unique()->count(),
                'weight' => $advisories->sum(fn (Advisory $advisory) => $weight($advisory->severity)),
                'services' => array_values($advisories->unique('dependency_id')->sortBy('dependency.service.name')->map(fn (Advisory $advisory) => [
                    'id' => $advisory->dependency->service->id,
                    'name' => $advisory->dependency->service->name,
                    'environment' => $advisory->dependency->service->environment,
                    'from' => $advisory->dependency->version,
                ])->all()),
            ])
            ->sortByDesc('weight')->take($limit)->all());
    }

    /**
     * @param  Collection<int, Dependency>  $dependencies  one package across services
     * @return array<string, mixed>
     */
    private function present(Collection $dependencies): array
    {
        $first = $dependencies->first();

        return [
            'ecosystem' => $first?->ecosystem,
            'name' => $first?->name,
            'status' => ServiceStatus::worst($dependencies->pluck('status')),
            'services_count' => $dependencies->count(),
            'versions' => $dependencies->groupBy('version')
                ->sortKeysUsing(fn (int|string $a, int|string $b) => version_compare(ltrim((string) $b, 'v'), ltrim((string) $a, 'v')))
                ->map(fn (Collection $running, int|string $version) => [
                    'version' => (string) $version,
                    'status' => ServiceStatus::worst($running->pluck('status')),
                    'services' => $running->sortBy('service.name')->map(fn (Dependency $dependency) => [
                        'id' => $dependency->service->id,
                        'name' => $dependency->service->name,
                        'environment' => $dependency->service->environment,
                    ])->values(),
                ])->values(),
        ];
    }
}
