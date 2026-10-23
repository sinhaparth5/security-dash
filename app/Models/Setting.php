<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An integration value saved from the settings page. It wins over the .env value of the same config key.
 *
 * @property string $key
 * @property string $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** Config keys the settings page may change, and whether the value is a secret. */
    public const KEYS = [
        'services.alerts.webhook_url' => true,
        'services.typesafe.key' => true,
        'services.typesafe.model' => false,
        'services.github.pr_token' => true,
    ];

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The saved value for a config key, falling back to config (and so .env).
     */
    public static function config(string $key): mixed
    {
        // ponytail: one primary-key query per read, no cache, so queue workers see changes at once
        // and decrypted secrets never land in the cache store. Cache encrypted rows if reads get hot.
        return self::find($key)->value ?? config($key);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
