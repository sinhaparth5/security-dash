<?php

namespace App\Models;

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use Database\Factories\DependencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $service_id
 * @property Ecosystem $ecosystem
 * @property string $name
 * @property string $version
 * @property string|null $latest_version
 * @property ServiceStatus $status
 * @property Carbon|null $eol_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['ecosystem', 'name', 'version', 'latest_version', 'status', 'eol_at'])]
class Dependency extends Model
{
    /** @use HasFactory<DependencyFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<Advisory, $this>
     */
    public function advisories(): HasMany
    {
        return $this->hasMany(Advisory::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ecosystem' => Ecosystem::class,
            'status' => ServiceStatus::class,
            'eol_at' => 'date',
        ];
    }
}
