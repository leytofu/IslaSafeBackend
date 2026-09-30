<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SosRequestController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/sos-requests', [SosRequestController::class, 'index'])
        ->middleware('role:admin,campmanager');
    Route::patch('/sos-requests/{sosRequest}', [SosRequestController::class, 'update'])
        ->middleware('role:admin,campmanager');
});

// Emergency submissions are intentionally public — reporting an SOS must
// never be blocked by authentication.
Route::post('/sos-requests', [SosRequestController::class, 'store']);
