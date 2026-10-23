<?php

namespace App\Upstream;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;

/**
 * Security news from free RSS feeds. No API key: every source publishes a public feed.
 */
class SecurityNews
{
    /**
     * ponytail: a fixed list of English feeds; move it to config if teams want their own sources.
     */
    public const FEEDS = [
        'The Hacker News' => 'https://feeds.feedburner.com/TheHackersNews',
        'BleepingComputer' => 'https://www.bleepingcomputer.com/feed/',
        'SecurityWeek' => 'https://www.securityweek.com/feed/',
        'Krebs on Security' => 'https://krebsonsecurity.com/feed/',
        'Dark Reading' => 'https://www.darkreading.com/rss.xml',
    ];

    /**
     * The latest articles from every feed. A feed that is down or malformed is skipped, not fatal.
     *
     * @return list<array{url: string, source: string, title: string, summary: string|null, image_url: string|null, published_at: CarbonInterface}>
     */
    public function latest(): array
    {
        $articles = [];

        foreach (self::FEEDS as $source => $url) {
            $body = rescue(fn () => Http::timeout(15)->withUserAgent('Patchwatch news reader')->get($url)->throw()->body(), null, report: true);

            if (is_string($body)) {
                array_push($articles, ...self::parse($source, $body));
            }
        }

        return $articles;
    }

    /**
     * @return list<array{url: string, source: string, title: string, summary: string|null, image_url: string|null, published_at: CarbonInterface}>
     */
    public static function parse(string $source, string $xml): array
    {
        // Feeds are untrusted input: no network access while parsing, and libxml errors stay quiet.
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($feed === false || ! isset($feed->channel->item)) {
            return [];
        }

        $articles = [];

        foreach ($feed->channel->item as $item) {
            $url = trim((string) $item->link);
            $title = self::text((string) $item->title);

            if (! self::isWebUrl($url) || $title === '') {
                continue;
            }

            $content = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
            // Feeds carry their own offsets, and timestamps are stored without one, so convert first.
            $published = rescue(fn () => Date::parse((string) $item->pubDate)->setTimezone(config('app.timezone')), null, report: false);
            // WordPress feeds append "The post … appeared first on …" to every description.
            $summary = self::text((string) preg_replace('/<p>The post .*$/s', '', (string) $item->description));

            $articles[] = [
                'url' => $url,
                'source' => $source,
                'title' => Str::limit($title, 480),
                'summary' => $summary === '' ? null : Str::limit($summary, 280),
                'image_url' => self::image($item, $content.(string) $item->description),
                'published_at' => $published ?? now(),
            ];
        }

        return $articles;
    }

    /**
     * The article image from <enclosure>, <media:content>, <media:thumbnail>, or the first <img> in its HTML.
     */
    private static function image(SimpleXMLElement $item, string $html): ?string
    {
        $media = $item->children('http://search.yahoo.com/mrss/');
        $enclosure = $item->enclosure;

        $candidates = [
            str_starts_with((string) $enclosure['type'], 'image/') ? (string) $enclosure['url'] : null,
            isset($media->content) ? (string) $media->content->attributes()?->url : null,
            isset($media->thumbnail) ? (string) $media->thumbnail->attributes()?->url : null,
            preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $match) ? html_entity_decode($match[1]) : null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && self::isWebUrl($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Plain text from feed HTML: tags stripped, entities decoded, whitespace collapsed.
     */
    private static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    /**
     * Only http(s) links reach the page, so a feed can't inject javascript: or data: URLs.
     */
    private static function isWebUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s]+$#i', $url) === 1 && strlen($url) <= 2048;
    }
}
