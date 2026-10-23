<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A security news article from one of the RSS feeds in App\Upstream\SecurityNews.
 *
 * @property int $id
 * @property string $url_hash
 * @property string $url
 * @property string $source
 * @property string $title
 * @property string|null $summary
 * @property string|null $image_url
 * @property Carbon $published_at
 * @property float|null $importance 0 (background) to 3 (act now), from Jev
 * @property float|null $importance_confidence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['url_hash', 'url', 'source', 'title', 'summary', 'image_url', 'published_at', 'importance', 'importance_confidence'])]
class NewsArticle extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'importance' => 'float',
            'importance_confidence' => 'float',
        ];
    }
}
