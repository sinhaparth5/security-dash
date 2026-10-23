<?php

namespace App\Http\Controllers;

use App\Enums\ServiceStatus;
use App\Models\Dependency;
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
        ]);
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
