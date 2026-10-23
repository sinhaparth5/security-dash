<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $service_id
 * @property string $action
 * @property array<string, mixed>|null $details
 * @property string|null $ip
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'service_id', 'action', 'details', 'ip'])]
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * Record who did what to which service. The user and IP come from the current request;
     * both are null for console commands.
     *
     * @param  array<string, mixed>  $details
     */
    public static function record(string $action, ?Service $service = null, array $details = [], ?User $user = null): self
    {
        $user ??= auth()->guard('web')->user();

        return self::create([
            'user_id' => $user?->id,
            'service_id' => $service?->id,
            'action' => $action,
            'details' => $details ?: null,
            'ip' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip(),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
