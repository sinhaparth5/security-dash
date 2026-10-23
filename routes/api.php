<?php

use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\ImageScanController;
use Illuminate\Support\Facades\Route;

// ponytail: flat 60/min per token; split heartbeat and image-scan limits if CI bursts ever hit it.
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('telemetry/heartbeat', HeartbeatController::class)->name('api.v1.heartbeat');
    Route::post('telemetry/image-scan', ImageScanController::class)->name('api.v1.image-scan');
});
