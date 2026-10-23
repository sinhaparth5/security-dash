<?php

namespace App\Upstream;

use App\Models\Advisory;
use App\Models\NewsArticle;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Advisory triage by TypeSafe's Jev model: how soon to fix each advisory, and the best next step.
 * Jev returns typed answers with confidence, so the dashboard shows them as suggestions, never as grades.
 */
class Jev
{
    public const API = 'https://api.typesafe.ai/v1/systemone';

    public const URGENCY = [
        'Routine: fix with the next regular dependency update. Exploitation is unlikely or the impact is minor.',
        'Soon: schedule within a couple of weeks. Real impact, but no sign of active exploitation.',
        'Urgent: fix within days. Serious impact, or exploitation is likely.',
        'Emergency: fix today. Actively exploited, malicious, or trivially exploitable with severe impact.',
    ];

    public const ACTIONS = [
        'upgrade' => 'Upgrade the package to the fixed version: a fix exists and upgrading is the straightforward remedy.',
        'investigate' => 'Check whether the service uses the affected feature before deciding: the advisory only applies under specific usage or configuration.',
        'accept' => 'Likely safe to accept the risk for now: low impact, or it needs conditions that are unlikely in a deployed service.',
        'replace' => 'Remove or replace the package: it is malicious, abandoned, or has no fix.',
    ];

    public const NEWS_IMPORTANCE = [
        'Background: industry news, opinion, funding, policy or research with nothing for this team to do.',
        'Worth knowing: a notable incident or vulnerability, but unlikely to touch the software this team runs.',
        'Important: a serious vulnerability, breach or active campaign that could plausibly affect the software or vendors this team runs.',
        'Act now: an actively exploited or widespread vulnerability in software this team runs, or a supply-chain attack on its ecosystems.',
    ];

    public function enabled(): bool
    {
        return filled(config('services.typesafe.key'));
    }

    /**
     * Triage per advisory id, for advisories with a dependency loaded.
     *
     * @param  Collection<int, Advisory>  $advisories
     * @return array<int, array{urgency: float, urgency_confidence: float, action: string, action_confidence: float}>
     */
    public function triage(Service $service, Collection $advisories): array
    {
        // ponytail: one request per service, capped at 50 advisories; split into several requests if services carry more.
        $advisories = $advisories->take(50)->values();

        if ($advisories->isEmpty()) {
            return [];
        }

        $state = [
            'service' => ['name' => $service->name, 'environment' => $service->environment],
            'advisories' => $advisories->map(fn (Advisory $advisory) => [
                'id' => $advisory->osv_id,
                'package' => $advisory->dependency->name,
                'ecosystem' => $advisory->dependency->ecosystem->value,
                'installed_version' => $advisory->dependency->version,
                'fixed_version' => $advisory->fixed_version,
                'severity' => $advisory->severity,
                'summary' => $advisory->summary,
                'malware' => $advisory->isMalicious(),
                'in_cisa_known_exploited_catalog' => $advisory->known_exploited,
                'epss_30_day_exploit_probability' => $advisory->epss === null ? null : round($advisory->epss, 2),
            ])->all(),
        ];

        $questions = [];

        foreach ($advisories->keys() as $i) {
            $questions["urgency_{$i}"] = [
                'type' => 'score',
                'instructions' => "How soon should the team running `service` fix the advisory at `advisories[{$i}]`?",
                'criteria' => self::URGENCY,
            ];
            $questions["action_{$i}"] = [
                'type' => 'choice',
                'instructions' => "What is the best next step for the advisory at `advisories[{$i}]` in `service`?",
                'criteria' => self::ACTIONS,
            ];
        }

        // Re-evaluations run hourly; only a changed advisory set (or changed KEV/EPSS signal) is asked again.
        $answers = Cache::remember('upstream:jev:'.sha1((string) json_encode($state)), now()->addDays(7), fn () => Http::withToken((string) config('services.typesafe.key'))
            ->acceptJson()->timeout(30)->retry(2, 1000)
            ->post(self::API, ['model' => config('services.typesafe.model'), 'state' => $state, 'questions' => $questions])
            ->throw()->json('answers'));

        return $advisories->mapWithKeys(fn (Advisory $advisory, int $i) => [$advisory->id => [
            'urgency' => round((float) $answers["urgency_{$i}"]['score'], 2),
            'urgency_confidence' => round((float) $answers["urgency_{$i}"]['confidence'], 2),
            'action' => (string) $answers["action_{$i}"]['choice'],
            'action_confidence' => round((float) $answers["action_{$i}"]['confidence'], 2),
        ]])->all();
    }

    /**
     * How much each news article matters to a team running the given stack, keyed by article id.
     *
     * @param  Collection<int, NewsArticle>  $articles
     * @param  array{ecosystems: list<string>, runtimes: list<string>, packages: list<string>}  $stack
     * @return array<int, array{importance: float, importance_confidence: float}>
     */
    public function rankNews(Collection $articles, array $stack): array
    {
        $articles = $articles->values();

        if ($articles->isEmpty()) {
            return [];
        }

        $state = [
            'team_stack' => $stack,
            'articles' => $articles->map(fn (NewsArticle $article) => [
                'source' => $article->source,
                'title' => $article->title,
                'summary' => $article->summary,
            ])->all(),
        ];

        $questions = [];

        foreach ($articles->keys() as $i) {
            $questions["importance_{$i}"] = [
                'type' => 'score',
                'instructions' => "How much does the news article at `articles[{$i}]` matter to the team whose software is described in `team_stack`?",
                'criteria' => self::NEWS_IMPORTANCE,
            ];
        }

        $answers = Http::withToken((string) config('services.typesafe.key'))
            ->acceptJson()->timeout(30)->retry(2, 1000)
            ->post(self::API, ['model' => config('services.typesafe.model'), 'state' => $state, 'questions' => $questions])
            ->throw()->json('answers');

        return $articles->mapWithKeys(fn (NewsArticle $article, int $i) => [$article->id => [
            'importance' => round((float) $answers["importance_{$i}"]['score'], 2),
            'importance_confidence' => round((float) $answers["importance_{$i}"]['confidence'], 2),
        ]])->all();
    }
}
