<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ecosystem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\HeartbeatRequest;
use App\Jobs\EvaluateService;
use App\Models\Service;
use Illuminate\Http\Response;

class HeartbeatController extends Controller
{
    /**
     * Replace the service's dependency manifest with the reported one.
     * Image rows belong to CI scans and are left alone.
     */
    public function __invoke(HeartbeatRequest $request): Response
    {
        /** @var Service $service */
        $service = $request->user();

        /** @var array<string, array{ecosystem: string, name: string, version: string}> $reported */
        $reported = $request->safe()->collect('dependencies')
            ->keyBy(fn (array $dependency) => $dependency['ecosystem'].'|'.$dependency['name'])
            ->all();

        $service->syncDependencies($reported, [Ecosystem::Go, Ecosystem::Npm, Ecosystem::Runtime]);
        EvaluateService::dispatch($service);

        return response()->noContent();
    }
}
