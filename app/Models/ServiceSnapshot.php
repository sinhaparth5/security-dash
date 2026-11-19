<?php

namespace App\Models;

use App\Enums\ServiceStatus;
use App\Upstream\Releases;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A service's grade and dependency drift on one day. Informational: grading never reads it.
 *
 * @property int $id
 * @property int $service_id
 * @property Carbon $date
 * @property ServiceStatus $status
 * @property int|null $dependencies null on rows backfilled from status history, before drift was measured
 * @property int|null $outdated newer release available
 * @property int|null $majors_behind summed over dependencies
 * @property int|null $end_of_life runtimes past end of life
 */
#[Fillable(['service_id', 'date', 'status', 'dependencies', 'outdated', 'majors_behind', 'end_of_life'])]
class ServiceSnapshot extends Model
{
    public $timestamps = false;

    /**
     * Plain dates, so a day's row is found by its 'Y-m-d' key on every driver.
     */
    protected $dateFormat = 'Y-m-d';

    /**
     * Write today's row for every service; running twice in a day overwrites it.
     */
    public static function take(): void
    {
        $today = today()->toDateString();

        Service::with('dependencies:id,service_id,version,latest_version,eol_at')->each(fn (Service $service) => self::updateOrCreate(
            ['service_id' => $service->id, 'date' => $today],
            ['status' => $service->status] + self::drift($service),
        ));
    }

    /**
     * How far a service's dependencies lag their latest release, from what grading already stored.
     *
     * @return array{dependencies: int, outdated: int, majors_behind: int, end_of_life: int}
     */
    public static function drift(Service $service): array
    {
        $tracked = $service->dependencies->filter(fn (Dependency $dependency) => $dependency->latest_version !== null);
        // ponytail: whole-number majors; a 0.x line moving minor (0.3 → 0.9) counts as 0, as does a Go /v2 module path.
        $major = fn (string $version) => (int) Releases::normalize($version);

        return [
            'dependencies' => $service->dependencies->count(),
            'outdated' => $tracked->filter(fn (Dependency $dependency) => version_compare(Releases::normalize($dependency->version), Releases::normalize((string) $dependency->latest_version), '<'))->count(),
            'majors_behind' => (int) $tracked->sum(fn (Dependency $dependency) => max(0, $major((string) $dependency->latest_version) - $major($dependency->version))),
            'end_of_life' => $service->dependencies->filter(fn (Dependency $dependency) => $dependency->eol_at?->isPast() ?? false)->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => ServiceStatus::class,
        ];
    }
}
