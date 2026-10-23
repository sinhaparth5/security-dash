<?php

use App\Jobs\EvaluateService;
use App\Models\Service;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => Service::query()->each(fn (Service $service) => EvaluateService::dispatch($service)))
    ->name('evaluate-services')
    ->hourly();

Schedule::command('sanctum:prune-expired --hours=24')->daily();
