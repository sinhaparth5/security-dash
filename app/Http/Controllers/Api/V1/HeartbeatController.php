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
     * Replace the service's dependency manifest, graph and usage scan with the reported ones.
     * Image rows belong to CI scans and are left alone.
     */
    public function __invoke(HeartbeatRequest $request): Response
    {
        /** @var Service $service */
        $service = $request->user();

        $service->syncDependencies($request->manifest(), [Ecosystem::Go, Ecosystem::Npm, Ecosystem::Runtime]);
        $service->update(['requires' => $request->rootRequires(), 'usage_scanned_at' => $request->usageScanned() ? now() : null]);
        EvaluateService::dispatch($service);

        return response()->noContent();
    }
}
