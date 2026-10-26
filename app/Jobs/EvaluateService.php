<?php

namespace App\Jobs;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\DependencyGraph;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ServiceTurnedRed;
use App\Upstream\Exploitability;
use App\Upstream\GitHub;
use App\Upstream\Jev;
use App\Upstream\Osv;
use App\Upstream\Releases;
use App\Upstream\SupplyChain;
use App\Upstream\UpgradePlanner;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

class EvaluateService implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Under the database queue's 90-second retry_after, so a slow evaluation isn't picked up twice.
     */
    public int $timeout = 85;

    public function __construct(public Service $service) {}

    public function uniqueId(): string
    {
        return (string) $this->service->id;
    }

    /**
     * Grade every dependency, then roll the worst grade up to the service:
     * Red = known advisory with a fix to upgrade to (or malware, or a runtime past end of life),
     * Yellow = newer release available or an advisory with no fix released yet, Green = neither.
     * Advisories with an active risk acceptance don't count towards Red, and neither do fixable ones in npm
     * packages the usage scan shows the code never reaches, or Go advisories whose vulnerable functions the symbol scan
     * shows aren't linked into the binary (malware still does: install scripts run regardless).
     * Images keep the grade their last CI scan gave them unless every finding has been accepted.
     * Every user is mailed (and the alert webhook posted) when the service first turns Red, or when a malicious package first shows up.
     */
    public function handle(Osv $osv, Releases $releases, Exploitability $exploitability, Jev $jev, GitHub $github, UpgradePlanner $planner, SupplyChain $supplyChain): void
    {
        $previousStatus = $this->service->fresh()->status;
        $wasMalicious = $this->hasMaliciousAdvisory();
        $accepted = $this->service->riskAcceptances()->active()->pluck('osv_id');
        [$scannedImages, $dependencies] = $this->service->dependencies()->with('advisories')->get()
            ->partition(fn (Dependency $dependency) => $dependency->ecosystem === Ecosystem::Image);
        $osvAdvisories = $osv->advisories($dependencies);
        $releases->prefetch($dependencies);
        $graph = DependencyGraph::of($this->service, $dependencies);
        $isRed = fn (iterable $advisories) => collect($advisories)->pluck('osv_id')->diff($accepted)->isNotEmpty();
        // An advisory with nothing to upgrade to can't be patched, so it warns instead of turning Red.
        $isPatchable = fn (array $advisory) => $advisory['fixed_version'] !== null || preg_match('/^(MAL|EOL)-/', $advisory['osv_id']);
        $isMalicious = fn (array $advisory) => str_starts_with($advisory['osv_id'], 'MAL-');

        $evaluations = $dependencies->map(function (Dependency $dependency) use ($osvAdvisories, $releases, $isRed, $isPatchable, $isMalicious, $graph) {
            $advisories = $osvAdvisories[$dependency->id] ?? [];
            $endOfLife = $releases->endOfLife($dependency);

            foreach ([$releases->nodeSecurityAdvisory($dependency), $releases->endOfLifeAdvisory($dependency, $endOfLife)] as $synthetic) {
                if ($synthetic !== null) {
                    $advisories[] = $synthetic;
                }
            }

            $reachable = $graph->reachable($dependency);
            $advisories = array_map(fn (array $advisory) => $advisory + ['reachable' => $dependency->ecosystem === Ecosystem::Npm
                ? $reachable
                : $dependency->linksVulnerableCode($advisory['affected_packages'] ?? [], $advisory['affected_symbols'] ?? [])], $advisories);

            $latestVersion = $releases->latest($dependency);
            $isTracked = array_key_exists($dependency->id, $osvAdvisories) || $dependency->ecosystem === Ecosystem::Runtime;

            $status = match (true) {
                $isRed(array_filter($advisories, fn (array $advisory) => $isMalicious($advisory) || ($isPatchable($advisory) && $advisory['reachable'] !== false))) => ServiceStatus::Red,
                $isRed($advisories) => ServiceStatus::Yellow,
                $latestVersion !== null && version_compare(Releases::normalize($dependency->version), $latestVersion, '<') => ServiceStatus::Yellow,
                $isTracked => ServiceStatus::Green,
                default => ServiceStatus::Unknown,
            };

            return compact('dependency', 'advisories', 'latestVersion', 'status', 'endOfLife');
        });

        $imageStatuses = $scannedImages->mapWithKeys(fn (Dependency $image) => [$image->id => match (true) {
            $isRed($image->advisories) => ServiceStatus::Red,
            $image->status === ServiceStatus::Red => ServiceStatus::Green,
            default => $image->status,
        }]);

        DB::transaction(function () use ($evaluations, $scannedImages, $imageStatuses, $previousStatus) {
            foreach ($evaluations as $evaluation) {
                // Advisories are rebuilt every run; keep when each was first seen so fix-by dates don't reset.
                $firstSeen = $evaluation['dependency']->advisories->pluck('first_seen_at', 'osv_id');
                $evaluation['dependency']->advisories()->delete();
                $evaluation['dependency']->advisories()->createMany(array_map(
                    fn (array $advisory) => $advisory + ['first_seen_at' => $firstSeen[$advisory['osv_id']] ?? now()],
                    $evaluation['advisories'],
                ));
                $evaluation['dependency']->update([
                    'latest_version' => $evaluation['latestVersion'],
                    'status' => $evaluation['status'],
                    'eol_at' => $evaluation['endOfLife'],
                ]);
            }

            $scannedImages->each(fn (Dependency $image) => $image->update(['status' => $imageStatuses[$image->id]]));

            $status = ServiceStatus::worst($evaluations->pluck('status')->merge($imageStatuses->values()));
            $this->service->update(['status' => $status, 'evaluated_at' => now()]);

            if ($status !== $previousStatus) {
                $this->service->statusChanges()->create(['status' => $status]);
                $this->service->update(['status_changed_at' => now()]);
            }
        });

        // Alert before the best-effort steps: if one of them runs the job out of time, the retry already
        // sees the service Red and would never send it.
        $turnedRed = $previousStatus !== ServiceStatus::Red && $this->service->status === ServiceStatus::Red;
        $turnedMalicious = ! $wasMalicious && $this->hasMaliciousAdvisory();

        if ($turnedRed || $turnedMalicious) {
            $alert = new ServiceTurnedRed($this->service);
            Notification::send(User::all(), $alert);
            self::postWebhook($alert->summary());
        }

        $this->enrich($exploitability);
        $this->inspect($supplyChain);
        $this->plan($planner, $accepted->all());
        $this->triage($jev, $accepted->all());
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
     * Post the alert to the Slack or Discord incoming webhook, if one is configured. Best effort:
     * returns whether the webhook accepted it.
     */
    public static function postWebhook(string $text): bool
    {
        $url = (string) Setting::config('services.alerts.webhook_url');

        if ($url === '') {
            return false;
        }

        // Dependency names come from the service, so they must not ping anyone or smuggle in links:
        // Discord gets mentions disabled and link brackets escaped, Slack gets its control characters escaped.
        $payload = str_contains($url, 'discord')
            ? ['content' => ServiceTurnedRed::unlinked($text), 'allowed_mentions' => ['parse' => []]]
            : ['text' => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text)];

        return rescue(fn () => Http::timeout(10)->withoutRedirecting()->post($url, $payload)->throw()->successful(), false, report: true);
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

    private function hasMaliciousAdvisory(): bool
    {
        return Advisory::whereRelation('dependency', 'service_id', $this->service->id)->where('osv_id', 'like', 'MAL-%')->exists();
    }
}
