<?php

namespace App\Http\Controllers;

use App\Models\Dependency;
use App\Models\Service;
use App\Upstream\GitHub;
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

        try {
            $version = $github->openUpgrade($service, $dependency);
        } catch (RuntimeException $e) {
            return $this->failed($e->getMessage());
        } catch (RequestException $e) {
            report($e);

            return $this->failed("GitHub refused the request ({$e->response->status()}). Check the repository name and that GITHUB_PR_TOKEN can write to it.");
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Opened a PR bumping {$dependency->name} to {$version}."]);

        return back();
    }

    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
