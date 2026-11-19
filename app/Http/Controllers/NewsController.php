<?php

namespace App\Http\Controllers;

use App\Jobs\FetchSecurityNews;
use App\Models\Advisory;
use App\Models\NewsArticle;
use App\Models\Service;
use App\Upstream\Exploitability;
use App\Upstream\Jev;
use App\Upstream\SecurityNews;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class NewsController extends Controller
{
    /**
     * Jev scores 0 (background) to 3 (act now). From 1.5, which rounds to "important", an article
     * counts as important; the three highest of those are featured.
     */
    public const IMPORTANT = 1.5;

    public const FEATURED = 3;

    /**
     * The last two weeks of security news: articles naming an advisory a service has first, then featured and
     * important ones by Jev's ranking, then the rest newest first.
     */
    public function index(Jev $jev): Response
    {
        // A fresh install would wait up to an hour for the scheduler; fetch on the first visit instead.
        if (! Cache::has(FetchSecurityNews::CHECKED_AT)) {
            FetchSecurityNews::dispatch();
        }

        $articles = NewsArticle::where('published_at', '>=', now()->subDays(14))->latest('published_at')->limit(200)->get();

        $affected = $this->affectedServices($articles->pluck('advisory_ids')->flatten()->filter()->unique());
        // Read from the cache evaluations fill; fetching KEV here would put a 30-second download in a page load.
        $exploited = Cache::get(Exploitability::KEV_CACHE, []);
        $present = fn (NewsArticle $article) => $this->present($article, $affected, $exploited);

        [$fleet, $articles] = $articles->partition(fn (NewsArticle $article) => $this->affects($article, $affected) !== []);
        [$important, $latest] = $articles->partition(fn (NewsArticle $article) => $article->importance !== null && $article->importance >= self::IMPORTANT);
        $important = $important->sortByDesc(fn (NewsArticle $article) => [$article->importance, $article->published_at->getTimestamp()])->values();

        return Inertia::render('news/Index', [
            'fleet' => $fleet->map($present)->values(),
            'featured' => $important->take(self::FEATURED)->map($present)->values(),
            'important' => $important->slice(self::FEATURED)->map($present)->values(),
            'latest' => $latest->map($present)->values(),
            'sources' => array_keys(SecurityNews::FEEDS),
            'ranked' => $jev->enabled(),
            'checkedAt' => Cache::get(FetchSecurityNews::CHECKED_AT),
        ]);
    }

    /**
     * The services that carry each of the given CVE / GHSA ids, matched on an advisory's id or its aliases.
     *
     * @param  Collection<int, string>  $ids
     * @return array<string, list<array{id: int, name: string, environment: string}>>
     */
    private function affectedServices(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        $wanted = $ids->flip();
        // ponytail: reads every advisory in the fleet; filter in SQL on the aliases JSON if this passes ~100k rows.
        $serviceIds = Advisory::query()->join('dependencies', 'dependencies.id', '=', 'advisories.dependency_id')
            ->get(['advisories.osv_id', 'advisories.aliases', 'dependencies.service_id'])
            ->flatMap(fn (Advisory $advisory) => collect([$advisory->osv_id, ...($advisory->aliases ?? [])])
                ->filter(fn (string $id) => $wanted->has($id))
                ->map(fn (string $id) => [$id, (int) $advisory->getAttribute('service_id')]))
            ->unique(fn (array $pair) => implode(':', $pair))
            ->mapToGroups(fn (array $pair) => [$pair[0] => $pair[1]]);

        $services = Service::whereIn('id', $serviceIds->flatten()->unique())->orderBy('name')->get(['id', 'name', 'environment']);

        return $serviceIds->map(fn (Collection $ids) => array_values($services->whereIn('id', $ids)
            ->map(fn (Service $service) => $service->only(['id', 'name', 'environment']))->all()))->all();
    }

    /**
     * @param  array<string, list<array{id: int, name: string, environment: string}>>  $affected
     * @return list<array{id: int, name: string, environment: string}>
     */
    private function affects(NewsArticle $article, array $affected): array
    {
        return array_values(collect($article->advisory_ids ?? [])->flatMap(fn (string $id) => $affected[$id] ?? [])->unique('id')->all());
    }

    /**
     * @param  array<string, list<array{id: int, name: string, environment: string}>>  $affected
     * @param  array<string, true>  $exploited
     * @return array<string, mixed>
     */
    private function present(NewsArticle $article, array $affected, array $exploited): array
    {
        return [
            'id' => $article->id,
            'url' => $article->url,
            'source' => $article->source,
            'title' => $article->title,
            'summary' => $article->summary,
            'image_url' => $article->image_url,
            'published_at' => $article->published_at->toIso8601String(),
            'importance' => $article->importance,
            'importance_confidence' => $article->importance_confidence,
            'advisory_ids' => $article->advisory_ids ?? [],
            'affects' => $this->affects($article, $affected),
            'exploited' => collect($article->advisory_ids ?? [])->contains(fn (string $id) => isset($exploited[$id])),
        ];
    }
}
