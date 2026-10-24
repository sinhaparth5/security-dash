<?php

use App\Http\Controllers\Settings\IntegrationController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/integrations', [IntegrationController::class, 'edit'])->name('integrations.edit');
    Route::put('settings/integrations', [IntegrationController::class, 'update'])->name('integrations.update');
    Route::post('settings/integrations/webhook-test', [IntegrationController::class, 'testWebhook'])->middleware('throttle:5,1')->name('integrations.webhook-test');
    Route::get('settings/integrations/github-app', [IntegrationController::class, 'createGithubApp'])->middleware('throttle:10,1')->name('integrations.github-app.callback');
    Route::delete('settings/integrations/github-app', [IntegrationController::class, 'forgetGithubApp'])->name('integrations.github-app.destroy');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
