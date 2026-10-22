<?php

use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\ImageScanController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::post('telemetry/heartbeat', HeartbeatController::class)->name('api.v1.heartbeat');
    Route::post('telemetry/image-scan', ImageScanController::class)->name('api.v1.image-scan');
});
