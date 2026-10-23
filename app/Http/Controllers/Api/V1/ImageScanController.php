<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImageScanRequest;
use App\Jobs\EvaluateService;
use App\Models\Service;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ImageScanController extends Controller
{
    /**
     * Replace an image's advisories with the findings of its latest Trivy scan.
     *
     * The image is Red when the scan found anything, so severity and fix filtering
     * belong in CI (`--severity HIGH,CRITICAL --ignore-unfixed`).
     */
    public function __invoke(ImageScanRequest $request): Response
    {
        /** @var Service $service */
        $service = $request->user();
        [$name, $version] = $this->splitReference($request->validated('ArtifactName'));

        $advisories = collect($request->vulnerabilities())
            ->unique('VulnerabilityID')
            ->map(fn (array $vulnerability) => [
                'osv_id' => $vulnerability['VulnerabilityID'],
                'aliases' => [],
                'severity' => $vulnerability['Severity'] ?? null,
                'summary' => $this->summary($vulnerability),
                // The fix is a package version inside the image, not an image version to upgrade to.
                'fixed_version' => null,
            ])
            ->values()
            ->all();

        DB::transaction(function () use ($service, $name, $version, $advisories) {
            $image = $service->dependencies()->updateOrCreate(
                ['ecosystem' => Ecosystem::Image, 'name' => $name],
                ['version' => $version, 'latest_version' => null, 'status' => $advisories === [] ? ServiceStatus::Green : ServiceStatus::Red],
            );

            $image->advisories()->delete();
            $image->advisories()->createMany($advisories);
        });

        EvaluateService::dispatch($service);

        return response()->noContent();
    }

    /**
     * Split `registry:port/repo:tag@digest` into the repository and the most specific version.
     *
     * @return array{0: string, 1: string}
     */
    private function splitReference(string $reference): array
    {
        [$name, $digest] = explode('@', $reference, 2) + [1 => null];
        $tag = null;

        if (preg_match('/^(.+):([^:\/]+)$/', $name, $match)) {
            [, $name, $tag] = $match;
        }

        return [$name, $digest ?? $tag ?? 'latest'];
    }

    /**
     * @param  array{PkgName: string, InstalledVersion?: string|null, FixedVersion?: string|null, Title?: string|null}  $vulnerability
     */
    private function summary(array $vulnerability): string
    {
        $package = trim($vulnerability['PkgName'].' '.($vulnerability['InstalledVersion'] ?? ''));
        $fix = isset($vulnerability['FixedVersion']) ? " (fixed in {$vulnerability['FixedVersion']})" : '';

        return $package.$fix.(isset($vulnerability['Title']) ? ": {$vulnerability['Title']}" : '');
    }
}
