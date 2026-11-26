<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\NodeController;
use App\Http\Controllers\NodeDataController;
use App\Http\Controllers\NodeLiveController;
use App\Http\Controllers\NodeViewController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SourceProbeController;
use Illuminate\Support\Facades\Route;

// Limits are defined in AppServiceProvider::boot().
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Everything below requires a token; controllers check ownership.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/nodes/{node}/rows', [NodeDataController::class, 'index']);
    Route::get('/nodes/{node}/aggregate', [NodeDataController::class, 'aggregate']);
    Route::get('/nodes/{node}/live', [NodeLiveController::class, 'show']);

    Route::post('/nodes/{node}/views', [NodeViewController::class, 'store']);
    Route::get('/nodes/{node}/views', [NodeViewController::class, 'index']);
    Route::get('/views/{view}/rows', [NodeViewController::class, 'rows']);
    Route::delete('/views/{view}', [NodeViewController::class, 'destroy']);

    Route::get('/graph', [GraphController::class, 'index']);
    Route::get('/nodes/{node}/impact', [GraphController::class, 'impact']);

    Route::post('/nodes', [NodeController::class, 'store']);
    Route::delete('/nodes/{node}', [NodeController::class, 'destroy']);
    Route::post('/nodes/{node}/preview', [NodeController::class, 'preview']);
    Route::post('/nodes/{node}/apply', [NodeController::class, 'apply']);
    Route::post('/nodes/{node}/columns-schema', [NodeController::class, 'confirmColumnsSchema']);
    Route::get('/nodes/{node}/config-versions', [NodeController::class, 'configVersions']);
    Route::post('/nodes/{node}/config-versions/{version}/rollback', [NodeController::class, 'rollback']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    // Throttled so it can't be used as a scanner.
    Route::post('/sources/probe', [SourceProbeController::class, 'probe'])->middleware('throttle:10,1');
});
