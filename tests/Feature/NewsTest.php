<?php

use App\Enums\Ecosystem;
use App\Jobs\FetchSecurityNews;
use App\Models\Dependency;
use App\Models\NewsArticle;
use App\Models\User;
use App\Upstream\SecurityNews;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
});

function rssFeed(string $items): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
      <channel><title>Feed</title>{$items}</channel>
    </rss>
    XML;
}

test('feed items become articles with a plain summary and the image from whichever tag the feed uses', function () {
    $xml = rssFeed(<<<'XML'
        <item>
          <title>PHP &amp; friends patched</title>
          <link>https://example.com/php</link>
          <pubDate>Fri, 23 Oct 2026 08:48:00 -0400</pubDate>
          <description><![CDATA[<p>Seventy <b>percent</b> of sites</p> <p>The post PHP appeared first on SecurityWeek.</p>]]></description>
          <enclosure url="https://img.example.com/php.jpg" type="image/jpeg" length="1"/>
        </item>
        <item>
          <title>Media image</title>
          <link>https://example.com/media</link>
          <media:content url="https://img.example.com/media.jpg" medium="image"/>
        </item>
        <item>
          <title>Inline image</title>
          <link>https://example.com/inline</link>
          <content:encoded><![CDATA[<p><img src="https://img.example.com/inline.png" alt=""></p>]]></content:encoded>
        </item>
        <item>
          <title>Sneaky</title>
          <link>javascript:alert(1)</link>
        </item>
        <item>
          <title>Bad image</title>
          <link>https://example.com/bad</link>
          <enclosure url="javascript:alert(1)" type="image/png" length="1"/>
        </item>
        XML);

    $articles = SecurityNews::parse('SecurityWeek', $xml);

    expect($articles)->toHaveCount(4)
        ->and($articles[0])->toMatchArray([
            'url' => 'https://example.com/php',
            'source' => 'SecurityWeek',
            'title' => 'PHP & friends patched',
            'summary' => 'Seventy percent of sites',
            'image_url' => 'https://img.example.com/php.jpg',
        ])
        ->and($articles[0]['published_at']->toIso8601String())->toBe('2026-10-23T12:48:00+00:00')
        ->and($articles[1]['image_url'])->toBe('https://img.example.com/media.jpg')
        ->and($articles[2]['image_url'])->toBe('https://img.example.com/inline.png')
        ->and($articles[3]['image_url'])->toBeNull();

    expect(SecurityNews::parse('x', 'not xml'))->toBe([]);
});

test('the hourly job stores new articles once, ranks them with jev for the fleet stack and prunes old ones', function () {
    config(['services.typesafe.key' => 'ts-test']);
    Dependency::factory()->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash']);
    $old = NewsArticle::create(['url_hash' => sha1('https://example.com/old'), 'url' => 'https://example.com/old', 'source' => 'x', 'title' => 'Old', 'published_at' => now()->subDays(40)]);
    $feed = rssFeed('<item><title>Lodash RCE</title><link>https://example.com/lodash</link><pubDate>'.now()->toRssString().'</pubDate></item>'
        .'<item><title>Ancient</title><link>https://example.com/ancient</link><pubDate>'.now()->subDays(60)->toRssString().'</pubDate></item>');
    Http::fake([
        'feeds.feedburner.com/*' => Http::response($feed),
        'www.bleepingcomputer.com/*' => Http::response($feed),
        'www.securityweek.com/*' => Http::response('', 503),
        'krebsonsecurity.com/*' => Http::response('nonsense'),
        'www.darkreading.com/*' => Http::response(rssFeed('')),
        'api.typesafe.ai/*' => Http::response(['answers' => ['importance_0' => ['score' => 2.71, 'confidence' => 0.9]]]),
    ]);

    FetchSecurityNews::dispatchSync();
    FetchSecurityNews::dispatchSync();

    $this->assertModelMissing($old);
    expect(NewsArticle::sole())
        ->title->toBe('Lodash RCE')
        ->importance->toBe(2.71)
        ->importance_confidence->toBe(0.9);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'typesafe')
        && $request['state']['team_stack']['packages'] === ['lodash']
        && $request['state']['articles'][0]['title'] === 'Lodash RCE');
    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'typesafe'))->count())->toBe(1);
});

test('without a typesafe key articles are stored unranked', function () {
    config(['services.typesafe.key' => null]);
    Http::fake(['*' => Http::response(rssFeed('<item><title>News</title><link>https://example.com/n</link></item>'))]);

    FetchSecurityNews::dispatchSync();

    expect(NewsArticle::sole()->importance)->toBeNull();
});

test('the news page features the highest ranked articles, then important ones, then the rest newest first', function () {
    $article = fn (string $title, ?float $importance, int $hoursAgo) => NewsArticle::create([
        'url_hash' => sha1($title), 'url' => "https://example.com/{$title}", 'source' => 'x', 'title' => $title,
        'importance' => $importance, 'published_at' => now()->subHours($hoursAgo),
    ]);
    $article('routine', 0.4, 1);
    $article('unranked', null, 2);
    $article('urgent', 2.9, 5);
    $article('notable', 1.6, 3);
    $article('serious', 2.2, 4);
    $article('also-notable', 1.8, 6);
    $article('stale', 3.0, 24 * 20);

    $this->actingAs(User::factory()->create())
        ->get(route('news.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('news/Index')
            ->where('featured.0.title', 'urgent')
            ->where('featured.1.title', 'serious')
            ->where('featured.2.title', 'also-notable')
            ->where('important.0.title', 'notable')
            ->has('important', 1)
            ->where('latest.0.title', 'routine')
            ->where('latest.1.title', 'unranked')
            ->has('latest', 2)
        );
});

test('news is fetched every hour and publisher images are allowed by the csp', function () {
    Queue::fake();

    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'security-news');
    expect($event->expression)->toBe('0 * * * *');

    $csp = $this->actingAs(User::factory()->create())->get(route('news.index'))->headers->get('Content-Security-Policy');
    expect($csp)->toContain("img-src 'self' data: https:");
});

test('the first visit fetches news instead of waiting for the next hourly run', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('news.index'))->assertOk();
    Queue::assertPushed(FetchSecurityNews::class, 1);

    Cache::forever(FetchSecurityNews::CHECKED_AT, now()->toIso8601String());
    $this->actingAs($user)->get(route('news.index'))->assertOk();
    Queue::assertPushed(FetchSecurityNews::class, 1);
});
