<?php

use App\Jobs\EvaluateService;
use App\Jobs\FetchSecurityNews;
use App\Models\Service;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => Service::query()->each(fn (Service $service) => EvaluateService::dispatch($service)))
    ->name('evaluate-services')
    ->hourly();

Schedule::job(new FetchSecurityNews)
    ->name('security-news')
    ->hourly();

Schedule::call(fn () => Notification::send(User::all(), new WeeklyDigest))
    ->name('weekly-digest')
    ->weeklyOn(1, '8:00');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
