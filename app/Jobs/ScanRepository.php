<?php

namespace App\Jobs;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Upstream\GitHub;
use App\Upstream\RepositoryScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Feed a watched service from its repository: read the dependencies at a commit on the default branch,
 * grade them, and report the grade as a check on that commit.
 */
class ScanRepository implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Under the database queue's 90-second retry_after, so a slow scan isn't picked up twice.
     */
    public int $timeout = 85;

    /**
     * @param  string|null  $sha  the commit to scan; null for the default branch as it is now
     */
    public function __construct(public Service $service, public ?string $sha = null) {}

    public function handle(RepositoryScanner $scanner, GitHub $github): void
    {
        $repository = (string) $this->service->repository;
        $sha = $this->sha ?? $github->head($repository);
        // Checks need the app; without it the scan still feeds the dashboard.
        $check = rescue(fn () => $github->checkRun($repository, $sha, ['status' => 'in_progress', 'output' => ['title' => 'Grading dependencies…', 'summary' => 'Patchwatch is reading this commit\'s lockfiles.']]), null, report: false);

        try {
            $scan = $scanner->scan($repository, $sha);
        } catch (RuntimeException $e) {
            if ($check !== null) {
                $github->checkRun($repository, $sha, ['status' => 'completed', 'conclusion' => 'neutral', 'output' => ['title' => 'Couldn\'t read the dependencies', 'summary' => $e->getMessage()]], $check);
            }

            return;
        }

        $this->service->syncDependencies($scan['dependencies'], [Ecosystem::Go, Ecosystem::Npm]);
        $this->service->update(['requires' => $scan['requires'], 'usage_scanned_at' => $scan['usage_scanned'] ? now() : null]);
        EvaluateService::dispatchSync($this->service);

        if ($check === null) {
            return;
        }

        $service = $this->service->fresh();
        $counts = $service->dependencies()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $github->checkRun($repository, $sha, [
            'status' => 'completed',
            'conclusion' => match ($service->status) {
                ServiceStatus::Red => 'failure',
                ServiceStatus::Green => 'success',
                default => 'neutral',
            },
            'details_url' => route('services.show', $service),
            'output' => [
                'title' => match ($service->status) {
                    ServiceStatus::Red => 'Vulnerable dependencies with a fix available',
                    ServiceStatus::Yellow => 'Updates or unfixed advisories to watch',
                    ServiceStatus::Green => 'All dependencies clear',
                    ServiceStatus::Unknown => 'Nothing Patchwatch can grade yet',
                },
                'summary' => implode("\n", [
                    "**{$service->name}** is graded **{$service->status->name}** at this commit.",
                    '',
                    '| Needs a security fix | Updates available | Unknown | Clear |',
                    '| --- | --- | --- | --- |',
                    '| '.implode(' | ', array_map(fn (ServiceStatus $status) => (int) ($counts[$status->value] ?? 0), [ServiceStatus::Red, ServiceStatus::Yellow, ServiceStatus::Unknown, ServiceStatus::Green])).' |',
                    '',
                    $scan['usage_scanned'] ? 'Imports were scanned, so fixable advisories in packages the code never reaches only warn.' : '',
                ]),
            ],
        ], $check);
    }
}
