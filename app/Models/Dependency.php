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
 * @property string|null $pull_request_url
 * @property string|null $pull_request_state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['ecosystem', 'name', 'version', 'latest_version', 'status', 'eol_at', 'pull_request_url', 'pull_request_state'])]
class Dependency extends Model
{
    /** @use HasFactory<DependencyFactory> */
    use HasFactory;

    /**
     * Widely used npm packages that typosquatters imitate.
     * ponytail: a fixed list; feed it from npm download counts if lookalikes of other packages matter.
     */
    public const POPULAR_NPM = [
        'axios', 'babel-core', 'body-parser', 'bootstrap', 'chalk', 'cheerio', 'classnames', 'commander', 'cookie-parser',
        'cors', 'cross-env', 'crypto-js', 'date-fns', 'debug', 'dotenv', 'electron', 'eslint', 'express', 'fs-extra',
        'glob', 'graphql', 'helmet', 'inquirer', 'jquery', 'jsonwebtoken', 'katex', 'lodash', 'marked', 'minimist',
        'mkdirp', 'moment', 'mongodb', 'mongoose', 'morgan', 'multer', 'mysql', 'next', 'nodemailer', 'nodemon',
        'passport', 'postcss', 'prettier', 'prop-types', 'puppeteer', 'react', 'react-dom', 'react-router', 'redis',
        'redux', 'request', 'rimraf', 'rollup', 'rxjs', 'sass', 'semver', 'sequelize', 'sharp', 'socket.io', 'svelte',
        'tailwindcss', 'typescript', 'underscore', 'uuid', 'validator', 'vite', 'vue', 'webpack', 'winston', 'yargs', 'zod',
    ];

    /**
     * The popular package this npm name is one typo away from, if any: a hint to check it is the intended one.
     * Names under five characters are skipped because short names collide by chance.
     */
    public function lookalike(): ?string
    {
        if ($this->ecosystem !== Ecosystem::Npm || strlen($this->name) < 5 || in_array($this->name, self::POPULAR_NPM, true)) {
            return null;
        }

        // One added, dropped or changed character, or two neighbours swapped (lodahs).
        return collect(self::POPULAR_NPM)->first(fn (string $popular) => match (levenshtein($this->name, $popular)) {
            1 => true,
            2 => count_chars($this->name, 1) === count_chars($popular, 1),
            default => false,
        });
    }

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
