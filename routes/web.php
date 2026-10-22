<?php

use App\Http\Controllers\Auth\GithubLoginController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

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
});

require __DIR__.'/settings.php';
