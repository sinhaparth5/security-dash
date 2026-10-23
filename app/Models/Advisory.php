<?php

namespace App\Models;

use Database\Factories\AdvisoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $dependency_id
 * @property string $osv_id
 * @property list<string> $aliases
 * @property string|null $severity
 * @property string|null $summary
 * @property string|null $fixed_version
 * @property bool $known_exploited
 * @property float|null $epss
 * @property array{urgency: float, urgency_confidence: float, action: string, action_confidence: float}|null $triage
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['osv_id', 'aliases', 'severity', 'summary', 'fixed_version', 'known_exploited', 'epss', 'triage'])]
class Advisory extends Model
{
    /** @use HasFactory<AdvisoryFactory> */
    use HasFactory;

    /**
     * OSV publishes malware reports under MAL- ids: the package itself is hostile, not just buggy.
     */
    public function isMalicious(): bool
    {
        return str_starts_with($this->osv_id, 'MAL-');
    }

    /**
     * The CVE this advisory is about, from its id or aliases, for KEV and EPSS lookups.
     */
    public function cve(): ?string
    {
        return collect([$this->osv_id, ...$this->aliases])->first(fn (string $id) => str_starts_with($id, 'CVE-'));
    }

    /**
     * @return BelongsTo<Dependency, $this>
     */
    public function dependency(): BelongsTo
    {
        return $this->belongsTo(Dependency::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'known_exploited' => 'boolean',
            'epss' => 'float',
            'triage' => 'array',
        ];
    }
}
