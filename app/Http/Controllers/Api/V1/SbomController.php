<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ecosystem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SbomRequest;
use App\Jobs\EvaluateService;
use App\Models\Service;
use Illuminate\Http\Response;

class SbomController extends Controller
{
    /**
     * Replace the service's npm and Go dependencies with the packages in a CycloneDX SBOM,
     * e.g. from `syft`, `cdxgen` or `cyclonedx-gomod`. Runtime and image rows are left alone,
     * so a service can send its runtime by heartbeat and its packages by SBOM. The SBOM's dependency
     * graph, when it has one, replaces the service's; an SBOM says nothing about imports, so any usage scan is dropped.
     */
    public function __invoke(SbomRequest $request): Response
    {
        /** @var Service $service */
        $service = $request->user();

        $service->syncDependencies($request->dependencies(), [Ecosystem::Go, Ecosystem::Npm]);
        $service->update(['requires' => $request->rootRequires(), 'usage_scanned_at' => null]);
        EvaluateService::dispatch($service);

        return response()->noContent();
    }
}
