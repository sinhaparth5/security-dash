<?php

namespace App\Jobs;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\DependencyGraph;
use App\Models\Service;
use App\Upstream\Exploitability;
use App\Upstream\GitHub;
use App\Upstream\Jev;
use App\Upstream\SupplyChain;
use App\Upstream\UpgradePlanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * The best-effort steps after a grade: KEV/EPSS badges, supply-chain signals, the upgrade plan, Jev triage and upgrade PRs.
 * Kept out of EvaluateService so a slow GitHub, registry or Jev call can never hold up a grade or its alert.
 */
class EnrichService implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Best effort: the next evaluation queues it again, so a failure is not retried.
     */
    public int $tries = 1;

    /**
     * Under the database queue's 90-second retry_after, so a slow run isn't picked up twice.
     */
    public int $timeout = 85;

    /**
     * A stuck lock clears before the next hourly evaluation.
     */
    public int $uniqueFor = 3000;

    public function __construct(public Service $service) {}

    public function uniqueId(): string
    {
        return (string) $this->service->id;
    }

    public function handle(Exploitability $exploitability, SupplyChain $supplyChain, UpgradePlanner $planner, Jev $jev, GitHub $github): void
    {
        $accepted = $this->service->riskAcceptances()->active()->pluck('osv_id')->all();

        $this->enrich($exploitability);
        $this->inspect($supplyChain);
        $this->plan($planner, $accepted);
        $this->triage($jev, $accepted);
        $this->pullRequests($github);
    }

    /**
     * Refresh the state of PRs Patchwatch opened, then, when the service opted in, open one for
     * every Red dependency that has a known fix and no PR yet.
     */
    private function pullRequests(GitHub $github): void
    {
        if (! $github->enabled() || $this->service->repository === null) {
            return;
        }

        $dependencies = $this->service->dependencies()->with('advisories')->get();

        // ponytail: one GitHub call per tracked PR each run, best effort; use a pull_request webhook if there are hundreds.
        foreach ($dependencies->whereNotNull('pull_request_url')->where('pull_request_state', 'open') as $dependency) {
            $state = rescue(fn () => $github->pullRequestState((string) $dependency->pull_request_url), null, report: true);

            if ($state !== null) {
                $dependency->update(['pull_request_state' => $state]);
            }
        }

        if (! $this->service->auto_pull_requests) {
            return;
        }

        $candidates = $dependencies->filter(fn (Dependency $dependency) => $dependency->status === ServiceStatus::Red
            && $dependency->pull_request_url === null
            && isset(GitHub::MANIFESTS[$dependency->ecosystem->value]));

        foreach ($candidates as $dependency) {
            // A failed attempt (no fix yet, branch exists, manifest not found) is retried once a day, not hourly.
            if (Cache::add("auto-pr:{$dependency->id}:{$dependency->version}", true, now()->addDay())) {
                rescue(fn () => $github->openUpgrade($this->service, $dependency, automatic: true), report: false);
            }
        }
    }

    /**
     * Work out the cheapest upgrades that clear the fixable npm advisories, from the dependency graph.
     *
     * @param  array<int, string>  $accepted
     */
    private function plan(UpgradePlanner $planner, array $accepted): void
    {
        $graph = DependencyGraph::of($this->service, $this->service->dependencies()->with('advisories')->get());

        // ponytail: best effort like enrich(), a registry outage keeps the previous plan until the next evaluation.
        $plan = $graph->known() ? rescue(fn () => $planner->plan($graph, array_values($accepted)), null, report: true) : [];

        if ($plan !== null) {
            $this->service->update(['fix_plan' => $plan]);
        }
    }

    /**
     * Note the npm releases that look like supply-chain trouble. They inform, they don't grade.
     */
    private function inspect(SupplyChain $supplyChain): void
    {
        $dependencies = $this->service->dependencies()->where('ecosystem', Ecosystem::Npm)->get();

        // ponytail: best effort like enrich(), an outage keeps the previous signals until the next evaluation.
        $signals = rescue(fn () => $supplyChain->signals($dependencies), null, report: true);

        if ($signals === null) {
            return;
        }

        foreach ($dependencies as $dependency) {
            $dependency->update(['supply_chain' => $signals[$dependency->id] ?? null]);
        }
    }

    /**
     * Mark advisories that are in CISA KEV and attach their EPSS score.
     */
    private function enrich(Exploitability $exploitability): void
    {
        $advisories = Advisory::whereRelation('dependency', 'service_id', $this->service->id)->get()
            ->filter(fn (Advisory $advisory) => $advisory->cve() !== null);

        if ($advisories->isEmpty()) {
            return;
        }

        // ponytail: best effort, an outage only hides the badges until the next evaluation; grading never waits on it.
        $knownExploited = rescue(fn () => $exploitability->knownExploited(), [], report: true);
        $scores = rescue(fn () => $exploitability->epss($advisories->map(fn (Advisory $advisory) => (string) $advisory->cve())->values()->all()), [], report: true);

        foreach ($advisories as $advisory) {
            $advisory->update([
                'known_exploited' => isset($knownExploited[$advisory->cve()]),
                'epss' => $scores[$advisory->cve()] ?? null,
            ]);
        }
    }

    /**
     * Ask Jev how soon each counted advisory needs fixing and what to do about it.
     *
     * @param  array<int, string>  $accepted
     */
    private function triage(Jev $jev, array $accepted): void
    {
        if (! $jev->enabled()) {
            return;
        }

        $advisories = Advisory::with('dependency')->whereRelation('dependency', 'service_id', $this->service->id)
            ->whereNotIn('osv_id', $accepted)->orderBy('id')->get();

        // ponytail: best effort like enrich(), an outage only hides the suggestions until the next evaluation.
        foreach (rescue(fn () => $jev->triage($this->service, $advisories), [], report: true) as $id => $triage) {
            Advisory::whereKey($id)->update(['triage' => json_encode($triage)]);
        }
    }
}
