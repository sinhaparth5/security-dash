<?php

namespace App\Models;

use App\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One point where a service's rolled-up status changed; consecutive rows give "red since" and the timeline.
 *
 * @property int $id
 * @property int $service_id
 * @property ServiceStatus $status
 * @property Carbon $created_at
 */
#[Fillable(['status'])]
class StatusChange extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
