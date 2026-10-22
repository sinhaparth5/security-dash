<?php

use App\Http\Controllers\Api\V1\HeartbeatController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::post('telemetry/heartbeat', HeartbeatController::class)->name('api.v1.heartbeat');
});
