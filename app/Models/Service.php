<?php

namespace App\Models;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use Database\Factories\ServiceFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $environment
 * @property ServiceStatus $status
 * @property Carbon|null $last_ping_at
 * @property list<string>|null $allowed_ips
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $evaluated_at
 * @property string|null $repository
 * @property array<string, string|null>|null $requires the service's own npm dependencies and their ranges, the roots of its graph
 * @property Carbon|null $usage_scanned_at when the reporter last sent where the code imports its packages
 * @property list<array{action: 'refresh'|'bump'|'override', package: string, from: string|null, to: string, breaking: bool, fixes: list<string>, via: list<string>}>|null $fix_plan
 * @property bool $auto_pull_requests
 * @property string|null $badge_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'environment', 'status', 'last_ping_at', 'allowed_ips', 'status_changed_at', 'evaluated_at', 'repository', 'requires', 'usage_scanned_at', 'fix_plan', 'auto_pull_requests', 'badge_token'])]
class Service extends Model implements AuthenticatableContract
{
    /** @use HasFactory<ServiceFactory> */
    use Authenticatable, HasApiTokens, HasFactory;

    /**
     * @return HasMany<Dependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(Dependency::class);
    }

    /**
     * Replace the dependencies in the given ecosystems with the reported ones and mark the service as seen.
     *
     * A changed version resets that dependency to Unknown and drops its
     * advisories, since they were evaluated against the old version. Graph edges and usage,
     * when reported, are kept up to date either way.
     *
     * @param  array<string, array{ecosystem: string, name: string, version: string, requires?: array<string, string|null>|null, usage?: array{sites: list<array{file: string, line: int, kind: 'source'|'tooling'}>, bin: bool}|null}>  $reported  keyed by "ecosystem|name"
     * @param  list<Ecosystem>  $ecosystems
     */
    public function syncDependencies(array $reported, array $ecosystems): void
    {
        DB::transaction(function () use ($reported, $ecosystems) {
            $existing = $this->dependencies()->whereIn('ecosystem', $ecosystems)->get()
                ->keyBy(fn (Dependency $dependency) => $dependency->ecosystem->value.'|'.$dependency->name);

            // ponytail: one query per changed row, batch with upsert if manifests reach thousands
            foreach ($reported as $key => $dependency) {
                $current = $existing->pull($key);
                $graph = array_intersect_key($dependency, ['requires' => true, 'usage' => true]);

                if ($current === null) {
                    $this->dependencies()->create($dependency);
                } elseif ($current->version !== $dependency['version']) {
                    $current->advisories()->delete();
                    $current->update([
                        'version' => $dependency['version'],
                        'latest_version' => null,
                        'status' => ServiceStatus::Unknown,
                        'pull_request_url' => null,
                        'pull_request_state' => null,
                        ...$graph,
                    ]);
                } else {
                    $current->update($graph);
                }
            }

            $this->dependencies()->whereKey($existing->modelKeys())->delete();
            $this->update(['last_ping_at' => now()]);
        });
    }

    /**
     * @return HasMany<RiskAcceptance, $this>
     */
    public function riskAcceptances(): HasMany
    {
        return $this->hasMany(RiskAcceptance::class);
    }

    /**
     * @return HasMany<StatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(StatusChange::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'last_ping_at' => 'datetime',
            'allowed_ips' => 'array',
            'status_changed_at' => 'datetime',
            'evaluated_at' => 'datetime',
            'requires' => 'array',
            'usage_scanned_at' => 'datetime',
            'fix_plan' => 'array',
            'auto_pull_requests' => 'boolean',
        ];
    }
}
