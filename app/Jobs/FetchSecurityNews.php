<?php

namespace App\Jobs;

use App\Enums\Ecosystem;
use App\Models\Dependency;
use App\Models\NewsArticle;
use App\Upstream\Jev;
use App\Upstream\SecurityNews;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class FetchSecurityNews implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Articles older than this are deleted; the page only shows the last two weeks.
     */
    public const KEEP_DAYS = 30;

    /**
     * Cache key holding when the feeds were last read, shown on the news page.
     */
    public const CHECKED_AT = 'news:checked_at';

    /**
     * Store new articles from the feeds, ask Jev how much each matters to the fleet, and prune old ones.
     */
    public function handle(SecurityNews $news, Jev $jev): void
    {
        foreach ($news->latest() as $article) {
            if ($article['published_at']->gte(now()->subDays(self::KEEP_DAYS))) {
                NewsArticle::firstOrCreate(['url_hash' => sha1($article['url'])], $article);
            }
        }

        NewsArticle::where('published_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        Cache::forever(self::CHECKED_AT, now()->toIso8601String());

        if (! $jev->enabled()) {
            return;
        }

        // ponytail: 25 unranked articles per run, newest first; the next hourly run picks up the rest.
        $unranked = NewsArticle::whereNull('importance')
            ->where('published_at', '>=', now()->subDays(14))
            ->latest('published_at')
            ->limit(25)
            ->get();

        // Best effort: an outage leaves articles unranked, and the next run asks again.
        foreach (rescue(fn () => $jev->rankNews($unranked, $this->stack()), [], report: true) as $id => $rank) {
            NewsArticle::whereKey($id)->update($rank);
        }
    }

    /**
     * What the fleet runs, so Jev can tell news about it from news about everyone else.
     *
     * @return array{ecosystems: list<string>, runtimes: list<string>, packages: list<string>}
     */
    private function stack(): array
    {
        $names = fn (Ecosystem $ecosystem, int $limit) => array_values(Dependency::query()
            ->where('ecosystem', $ecosystem)
            ->selectRaw('name, count(*) as services_count')
            ->groupBy('name')
            ->orderByDesc('services_count')
            ->limit($limit)
            ->pluck('name')
            ->all());

        return [
            'ecosystems' => array_values(Dependency::query()->distinct()->pluck('ecosystem')->map(fn (Ecosystem $ecosystem) => $ecosystem->value)->all()),
            'runtimes' => $names(Ecosystem::Runtime, 10),
            // ponytail: the 100 most shared packages; enough context for ranking, small enough for one request.
            'packages' => [...$names(Ecosystem::Npm, 50), ...$names(Ecosystem::Go, 50)],
        ];
    }
}
