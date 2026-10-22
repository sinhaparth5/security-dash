<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\HeartbeatRequest;
use App\Models\Service;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class HeartbeatController extends Controller
{
    /**
     * Replace the service's dependency manifest with the reported one.
     *
     * A changed version resets that dependency to Unknown and drops its
     * advisories, since they were evaluated against the old version.
     */
    public function __invoke(HeartbeatRequest $request): Response
    {
        /** @var Service $service */
        $service = $request->user();

        /** @var array<string, array{ecosystem: string, name: string, version: string}> $reported */
        $reported = collect($request->validated('dependencies'))
            ->keyBy(fn (array $dependency) => $dependency['ecosystem'].'|'.$dependency['name'])
            ->all();

        DB::transaction(function () use ($service, $reported) {
            $existing = $service->dependencies()->get()
                ->keyBy(fn ($dependency) => $dependency->ecosystem->value.'|'.$dependency->name);

            // ponytail: one query per changed row, batch with upsert if manifests reach thousands
            foreach ($reported as $key => $dependency) {
                $current = $existing->pull($key);

                if ($current === null) {
                    $service->dependencies()->create($dependency);
                } elseif ($current->version !== $dependency['version']) {
                    $current->advisories()->delete();
                    $current->update([
                        'version' => $dependency['version'],
                        'latest_version' => null,
                        'status' => ServiceStatus::Unknown,
                    ]);
                }
            }

            $service->dependencies()->whereKey($existing->modelKeys())->delete();
            $service->update(['last_ping_at' => now()]);
        });

        return response()->noContent();
    }
}
