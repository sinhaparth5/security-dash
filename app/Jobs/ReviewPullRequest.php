<?php

namespace App\Jobs;

use App\Enums\Ecosystem;
use App\Models\Dependency;
use App\Models\DependencyGraph;
use App\Models\RiskAcceptance;
use App\Models\Service;
use App\Upstream\GitHub;
use App\Upstream\Osv;
use App\Upstream\RepositoryScanner;
use App\Upstream\SupplyChain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Review the dependency changes in a pull request as patchwatch-bot: scan the base and the head,
 * look up advisories for every package the PR adds or changes, trace whether the code reaches
 * them, flag suspicious releases, and report it all as a check run and one PR comment kept up to date.
 */
class ReviewPullRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Under the database queue's 90-second retry_after, so a slow review isn't picked up twice.
     */
    public int $timeout = 85;

    public function __construct(public string $repository, public int $number, public string $head, public string $base) {}

    /**
     * A new advisory fails the check when it's malware, or has a fix and the code may reach it. Anything
     * else worth knowing (no fix yet, not reached, a suspicious release, a lookalike name) is neutral.
     */
    public function handle(RepositoryScanner $scanner, GitHub $github, Osv $osv, SupplyChain $supplyChain): void
    {
        $check = $github->checkRun($this->repository, $this->head, ['status' => 'in_progress', 'output' => ['title' => 'Reviewing dependency changes…', 'summary' => 'Patchwatch is comparing this PR\'s lockfiles with its base.']]);

        try {
            $after = $scanner->scan($this->repository, $this->head);
        } catch (RuntimeException $e) {
            $github->checkRun($this->repository, $this->head, ['status' => 'completed', 'conclusion' => 'neutral', 'output' => ['title' => 'Couldn\'t read the dependencies', 'summary' => $e->getMessage()]], $check);

            return;
        }

        // A base without lockfiles yet means every package is new.
        $before = rescue(fn () => $scanner->scan($this->repository, $this->base), ['dependencies' => [], 'requires' => null, 'usage_scanned' => false], report: false);

        $ids = 0;
        $model = function (array $entry) use (&$ids): Dependency {
            $dependency = new Dependency($entry);
            $dependency->id = ++$ids;

            return $dependency;
        };

        $head = collect($after['dependencies'])->map($model);
        $changed = $head->filter(fn (Dependency $dependency, string $key) => ($before['dependencies'][$key]['version'] ?? null) !== $dependency->version);
        $previous = $changed->keys()->filter(fn (string $key) => isset($before['dependencies'][$key]))->mapWithKeys(fn (string $key) => [$key => $model($before['dependencies'][$key])]);
        $removed = array_diff_key($before['dependencies'], $after['dependencies']);

        $advisories = $osv->advisories($changed->values()->concat($previous->values()));
        $graph = new DependencyGraph($after['requires'], $head->values(), $after['usage_scanned']);
        $accepted = RiskAcceptance::active()
            ->whereIn('service_id', Service::whereRaw('lower(repository) = ?', [strtolower($this->repository)])->select('id'))
            ->pluck('osv_id');
        $signals = rescue(fn () => $supplyChain->signals($changed->values()), [], report: true);

        $findings = [];
        $warnings = [];
        $fixed = [];

        foreach ($changed as $key => $dependency) {
            $was = $previous->get($key);
            $old = collect($was === null ? [] : $advisories[$was->id] ?? [])->pluck('osv_id');
            $new = collect($advisories[$dependency->id] ?? [])->pluck('osv_id');
            $reachable = $dependency->ecosystem === Ecosystem::Npm ? $graph->reachable($dependency) : null;

            foreach ($advisories[$dependency->id] ?? [] as $advisory) {
                if (! $old->contains($advisory['osv_id']) && ! $accepted->contains($advisory['osv_id'])) {
                    $findings[] = ['dependency' => $dependency, 'advisory' => $advisory, 'reachable' => $reachable,
                        'blocking' => str_starts_with($advisory['osv_id'], 'MAL-') || ($advisory['fixed_version'] !== null && $reachable !== false)];
                }
            }

            if ($was !== null && $old->diff($new)->isNotEmpty()) {
                $fixed[] = "- {$this->label($dependency->name)} {$was->version} → {$dependency->version} clears ".$old->diff($new)->implode(', ');
            }

            foreach ($signals[$dependency->id] ?? [] as $signal) {
                $warnings[] = "- {$this->label($dependency->name.'@'.$dependency->version)}: {$signal['detail']}";
            }

            if ($dependency->lookalike() !== null && $was === null) {
                $warnings[] = "- {$this->label($dependency->name)} is one letter away from the popular {$this->label((string) $dependency->lookalike())}. Check it's the package you meant.";
            }
        }

        $blocking = collect($findings)->where('blocking', true)->count();
        $report = $this->report(collect($findings), $warnings, $fixed, $changed, $previous, count($removed));

        $github->checkRun($this->repository, $this->head, [
            'status' => 'completed',
            'conclusion' => match (true) {
                $blocking > 0 => 'failure',
                $findings !== [] || $warnings !== [] => 'neutral',
                default => 'success',
            },
            'output' => [
                'title' => match (true) {
                    $blocking > 0 => $blocking.' '.Str::plural('vulnerability', $blocking).' to fix before merging',
                    $findings !== [] || $warnings !== [] => count($findings) + count($warnings).' '.Str::plural('thing', count($findings) + count($warnings)).' worth a look',
                    default => 'No new dependency risks',
                },
                'summary' => mb_strcut($report, 0, 60000),
            ],
        ], $check);

        $github->comment($this->repository, $this->number, $findings === [] && $warnings === [] && $fixed === [] ? null : $report,
            "## Patchwatch dependency review\n\nNothing to flag as of `".substr($this->head, 0, 7).'`.');
    }

    /**
     * @param  Collection<int, array{dependency: Dependency, advisory: array{osv_id: string, aliases: list<string>, severity: string|null, summary: string|null, fixed_version: string|null, affected_packages: list<string>, affected_symbols: list<string>}, reachable: bool|null, blocking: bool}>  $findings
     * @param  list<string>  $warnings
     * @param  list<string>  $fixed
     * @param  Collection<string, Dependency>  $changed
     * @param  Collection<string, Dependency>  $previous
     */
    private function report(Collection $findings, array $warnings, array $fixed, Collection $changed, Collection $previous, int $removed): string
    {
        $added = $changed->count() - $previous->count();
        $lines = ['## Patchwatch dependency review', '', "This PR adds {$added}, changes {$previous->count()} and removes {$removed} ".Str::plural('package', $added + $previous->count() + $removed).'.'];

        if ($findings->isNotEmpty()) {
            $lines = [...$lines, '', '### Vulnerable packages it brings in', '', '| | Package | Advisory | Severity | Fixed in | Reached by your code |', '| --- | --- | --- | --- | --- | --- |'];

            foreach ($findings->sortByDesc('blocking') as $finding) {
                $advisory = $finding['advisory'];
                $sites = $finding['dependency']->usage['sites'] ?? [];
                $lines[] = '| '.($finding['blocking'] ? '❌' : '⚠️')
                    .' | '.$this->label($finding['dependency']->name.'@'.$finding['dependency']->version)
                    .' | [`'.$advisory['osv_id'].'`](https://osv.dev/vulnerability/'.rawurlencode($advisory['osv_id']).') '.$this->cell((string) $advisory['summary'])
                    .' | '.($advisory['severity'] ?? '—')
                    .' | '.($advisory['fixed_version'] ?? 'no fix yet')
                    .' | '.match ($finding['reachable']) {
                        true => 'Yes'.($sites === [] ? '' : ', `'.$this->cell($sites[0]['file']).':'.$sites[0]['line'].'`'),
                        false => 'No, nothing imports it',
                        null => '—',
                    }.' |';
            }
        }

        if ($warnings !== []) {
            $lines = [...$lines, '', '### Worth a second look', '', ...$warnings];
        }

        if ($fixed !== []) {
            $lines = [...$lines, '', '### Fixed by this PR', '', ...$fixed];
        }

        return implode("\n", [...$lines, '', '<sub>Reviewed at `'.substr($this->head, 0, 7).'` by [Patchwatch]('.route('dashboard').').</sub>']);
    }

    /**
     * A package name as inline code. Names come from the PR, so nothing in them may break out of it.
     */
    private function label(string $name): string
    {
        return '`'.str_replace(['`', '|', "\n"], '', $name).'`';
    }

    private function cell(string $text): string
    {
        return str_replace(['|', "\r", "\n", '`'], ['\|', ' ', ' ', "'"], mb_strimwidth($text, 0, 140, '…'));
    }
}
