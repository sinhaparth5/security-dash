<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sign-in is GitHub only, so none of Fortify's password, 2FA or passkey routes may exist.
        Fortify::ignoreRoutes();
        Passkeys::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureProductionUsesPostgres();
        $this->configureDefaults();
    }

    /**
     * SQLite is for local development only; refuse to boot production on anything else.
     */
    protected function ensureProductionUsesPostgres(): void
    {
        if ($this->app->isProduction() && config('database.default') !== 'pgsql') {
            throw new RuntimeException('Production must run on PostgreSQL: set DB_CONNECTION=pgsql.');
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
