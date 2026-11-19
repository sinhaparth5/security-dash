<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An advisory a service carried and, once an evaluation no longer finds it, when it was fixed.
 * Grading never reads this table; it is the record the fix-time analysis is built from.
 *
 * @property int $id
 * @property int $service_id
 * @property string $ecosystem
 * @property string $package
 * @property string $osv_id
 * @property string|null $severity
 * @property Carbon $first_seen_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $published_at
 */
#[Fillable(['service_id', 'ecosystem', 'package', 'osv_id', 'severity', 'first_seen_at', 'resolved_at', 'published_at'])]
class AdvisoryHistory extends Model
{
    protected $table = 'advisory_history';

    public $timestamps = false;

    /**
     * Record the advisories a service carries right now: new ones open, ones that came back reopen
     * (keeping when they were first seen), and open ones no longer present are resolved.
     */
    public static function sync(Service $service): void
    {
        $now = now();
        $current = Advisory::query()->join('dependencies', 'dependencies.id', '=', 'advisories.dependency_id')
            ->where('dependencies.service_id', $service->id)
            ->get(['advisories.osv_id', 'advisories.severity', 'advisories.first_seen_at', 'advisories.created_at', 'advisories.published_at', 'dependencies.ecosystem', 'dependencies.name'])
            ->map(fn (Advisory $advisory) => [
                'service_id' => $service->id,
                'ecosystem' => (string) $advisory->getAttribute('ecosystem'),
                'package' => (string) $advisory->getAttribute('name'),
                'osv_id' => $advisory->osv_id,
                'severity' => $advisory->severity,
                'first_seen_at' => $advisory->first_seen_at ?? $advisory->created_at ?? $now,
                'resolved_at' => null,
                'published_at' => $advisory->published_at,
            ]); // dependencies are unique per (service, ecosystem, name), so each key appears once

        $open = self::where('service_id', $service->id)->whereNull('resolved_at')->get();
        $fixed = $open->reject(fn (self $row) => $current->contains(fn (array $seen) => self::key($seen) === self::key($row->only(['ecosystem', 'package', 'osv_id']))));
        self::whereKey($fixed->modelKeys())->update(['resolved_at' => $now]);

        // first_seen_at is left out of the update columns, so a reopened advisory keeps its original date.
        self::upsert($current->values()->all(), ['service_id', 'ecosystem', 'package', 'osv_id'], ['severity', 'resolved_at', 'published_at']);
    }

    /**
     * @param  array{ecosystem: string, package: string, osv_id: string}|array<string, mixed>  $row
     */
    private static function key(array $row): string
    {
        return $row['ecosystem']."\0".$row['package']."\0".$row['osv_id'];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
