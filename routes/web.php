<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\GithubLoginController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PullRequestController;
use App\Http\Controllers\RiskAcceptanceController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Public on purpose: README badges are fetched by GitHub's image proxy, so the unguessable token is the key.
Route::get('badge/{token}.svg', BadgeController::class)->middleware('throttle:120,1')->name('badge.show');

Route::middleware('guest')->group(function () {
    Route::inertia('login', 'auth/Login')->name('login');

    Route::middleware('throttle:10,1')->group(function () {
        Route::get('auth/github', [GithubLoginController::class, 'redirect'])->name('auth.github');
        Route::get('auth/github/callback', [GithubLoginController::class, 'callback'])->name('auth.github.callback');
    });
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [GithubLoginController::class, 'destroy'])->name('logout');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::post('services', [ServiceController::class, 'store'])->middleware('throttle:20,1')->name('services.store');
    Route::get('tokens', [TokenController::class, 'index'])->name('tokens.index');
    Route::post('services/{service}/tokens', [TokenController::class, 'store'])->middleware('throttle:20,1')->name('services.tokens.store');
    Route::delete('services/{service}/tokens/{token}', [TokenController::class, 'destroy'])->name('services.tokens.destroy');
    Route::put('services/{service}/allowed-ips', [TokenController::class, 'updateAllowedIps'])->name('services.allowed-ips.update');
    Route::post('services/{service}/risk-acceptances', [RiskAcceptanceController::class, 'store'])->name('services.risk-acceptances.store');
    Route::delete('services/{service}/risk-acceptances/{riskAcceptance}', [RiskAcceptanceController::class, 'destroy'])->scopeBindings()->name('services.risk-acceptances.destroy');
    Route::put('services/{service}/repository', [ServiceController::class, 'updateRepository'])->name('services.repository.update');
    Route::post('services/{service}/dependencies/{dependency}/pull-request', [PullRequestController::class, 'store'])->middleware('throttle:10,1')->scopeBindings()->name('services.dependencies.pull-request.store');
    Route::post('services/{service}/badge', [ServiceController::class, 'rotateBadge'])->middleware('throttle:10,1')->name('services.badge.rotate');
    Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
    Route::get('news', [NewsController::class, 'index'])->name('news.index');
    Route::get('audit', AuditController::class)->name('audit.index');
});

require __DIR__.'/settings.php';
