<?php

namespace App\Jobs;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ServiceTurnedRed;
use App\Upstream\Osv;
use App\Upstream\Releases;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class EvaluateService implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public Service $service) {}

    public function uniqueId(): string
    {
        return (string) $this->service->id;
    }

    /**
     * Grade every dependency, then roll the worst grade up to the service:
     * Red = known advisory on the running version, Yellow = newer release available, Green = neither.
     * Images keep the grade their last CI scan gave them, but still count towards the service.
     * Every user is mailed once when the service first turns Red, not on every Red evaluation.
     */
    public function handle(Osv $osv, Releases $releases): void
    {
        $previousStatus = $this->service->fresh()->status;
        [$scannedImages, $dependencies] = $this->service->dependencies()->get()
            ->partition(fn (Dependency $dependency) => $dependency->ecosystem === Ecosystem::Image);
        $osvAdvisories = $osv->advisories($dependencies);

        $evaluations = $dependencies->map(function (Dependency $dependency) use ($osvAdvisories, $releases) {
            $advisories = $osvAdvisories[$dependency->id] ?? [];

            if ($nodeAdvisory = $releases->nodeSecurityAdvisory($dependency)) {
                $advisories[] = $nodeAdvisory;
            }

            $latestVersion = $releases->latest($dependency);
            $isTracked = array_key_exists($dependency->id, $osvAdvisories) || $dependency->ecosystem === Ecosystem::Runtime;

            $status = match (true) {
                $advisories !== [] => ServiceStatus::Red,
                $latestVersion !== null && version_compare(Releases::normalize($dependency->version), $latestVersion, '<') => ServiceStatus::Yellow,
                $isTracked => ServiceStatus::Green,
                default => ServiceStatus::Unknown,
            };

            return compact('dependency', 'advisories', 'latestVersion', 'status');
        });

        DB::transaction(function () use ($evaluations, $scannedImages) {
            foreach ($evaluations as $evaluation) {
                $evaluation['dependency']->advisories()->delete();
                $evaluation['dependency']->advisories()->createMany($evaluation['advisories']);
                $evaluation['dependency']->update([
                    'latest_version' => $evaluation['latestVersion'],
                    'status' => $evaluation['status'],
                ]);
            }

            $this->service->update(['status' => ServiceStatus::worst($evaluations->pluck('status')->merge($scannedImages->pluck('status')))]);
        });

        if ($previousStatus !== ServiceStatus::Red && $this->service->status === ServiceStatus::Red) {
            Notification::send(User::all(), new ServiceTurnedRed($this->service));
        }
    }
}
