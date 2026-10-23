<?php

namespace App\Http\Controllers;

use App\Jobs\FetchSecurityNews;
use App\Models\NewsArticle;
use App\Upstream\Jev;
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
     * The last two weeks of security news: featured and important articles by Jev's ranking, then the rest newest first.
     */
    public function index(Jev $jev): Response
    {
        // A fresh install would wait up to an hour for the scheduler; fetch on the first visit instead.
        if (! Cache::has(FetchSecurityNews::CHECKED_AT)) {
            FetchSecurityNews::dispatch();
        }

        $articles = NewsArticle::where('published_at', '>=', now()->subDays(14))->latest('published_at')->limit(200)->get();

        [$important, $latest] = $articles->partition(fn (NewsArticle $article) => $article->importance !== null && $article->importance >= self::IMPORTANT);
        $important = $important->sortByDesc(fn (NewsArticle $article) => [$article->importance, $article->published_at->getTimestamp()])->values();

        return Inertia::render('news/Index', [
            'featured' => $important->take(self::FEATURED)->map($this->present(...))->values(),
            'important' => $important->slice(self::FEATURED)->map($this->present(...))->values(),
            'latest' => $latest->map($this->present(...))->values(),
            'ranked' => $jev->enabled(),
            'checkedAt' => Cache::get(FetchSecurityNews::CHECKED_AT),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NewsArticle $article): array
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
        ];
    }
}
