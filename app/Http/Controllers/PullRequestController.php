<?php

namespace App\Http\Controllers;

use App\Models\Advisory;
use App\Models\AuditEvent;
use App\Models\Dependency;
use App\Models\Service;
use App\Upstream\GitHub;
use App\Upstream\Releases;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use RuntimeException;

class PullRequestController extends Controller
{
    /**
     * Open a PR in the service's repository that bumps one vulnerable dependency to the
     * lowest version clearing every advisory still counted against it.
     */
    public function store(GitHub $github, Service $service, Dependency $dependency): RedirectResponse
    {
        abort_unless($github->enabled() && $service->repository !== null, 404);

        $accepted = $service->riskAcceptances()->active()->pluck('osv_id');
        $advisories = $dependency->advisories->reject(fn (Advisory $advisory) => $accepted->contains($advisory->osv_id));
        $version = $advisories->pluck('fixed_version')->filter()
            ->sort(fn (string $a, string $b) => version_compare(Releases::normalize($b), Releases::normalize($a)))
            ->first();

        if ($version === null) {
            return $this->failed('No fixed version is known for these advisories yet.');
        }

        try {
            $url = $github->openVersionBump($service->repository, $dependency, $version, array_values($advisories->map(fn (Advisory $advisory) => $advisory->osv_id)->all()));
        } catch (RuntimeException $e) {
            return $this->failed($e->getMessage());
        } catch (RequestException $e) {
            report($e);

            return $this->failed("GitHub refused the request ({$e->response->status()}). Check the repository name and that GITHUB_PR_TOKEN can write to it.");
        }

        $dependency->update(['pull_request_url' => $url]);
        AuditEvent::record('pull_request.opened', $service, ['dependency' => $dependency->name, 'version' => $version, 'url' => $url]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Opened a PR bumping {$dependency->name} to {$version}."]);

        return back();
    }

    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
