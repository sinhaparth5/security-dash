<?php

namespace App\Models;

use Carbon\CarbonInterface;
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
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['osv_id', 'aliases', 'severity', 'summary', 'fixed_version', 'known_exploited', 'epss', 'triage', 'first_seen_at'])]
class Advisory extends Model
{
    /** @use HasFactory<AdvisoryFactory> */
    use HasFactory;

    /**
     * Days to fix an advisory after it first shows up, by severity. Unrated advisories get the moderate window.
     */
    public const SLA_DAYS = ['CRITICAL' => 2, 'HIGH' => 7, 'MODERATE' => 30, 'MEDIUM' => 30, 'LOW' => 90];

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
     * When the advisory should be fixed by: malware and actively exploited advisories get a day.
     */
    public function dueAt(): CarbonInterface
    {
        $days = $this->isMalicious() || $this->known_exploited ? 1 : self::SLA_DAYS[strtoupper((string) $this->severity)] ?? self::SLA_DAYS['MODERATE'];

        return ($this->first_seen_at ?? $this->created_at ?? now())->copy()->addDays($days);
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
            'first_seen_at' => 'datetime',
        ];
    }
}
