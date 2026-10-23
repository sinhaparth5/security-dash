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
 * @property bool $auto_pull_requests
 * @property string|null $badge_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'environment', 'status', 'last_ping_at', 'allowed_ips', 'status_changed_at', 'evaluated_at', 'repository', 'auto_pull_requests', 'badge_token'])]
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
     * advisories, since they were evaluated against the old version.
     *
     * @param  array<string, array{ecosystem: string, name: string, version: string}>  $reported  keyed by "ecosystem|name"
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
                    ]);
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
            'auto_pull_requests' => 'boolean',
        ];
    }
}
