<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\SosRequestController;
use App\Http\Controllers\Api\EvacuationController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

// Password recovery (OTP-based three-phase flow)
Route::post('/forgot-password', [PasswordResetController::class, 'requestOtp']);
Route::post('/forgot-password/verify-otp', [PasswordResetController::class, 'verifyOtp']);
Route::post('/forgot-password/resend-otp', [PasswordResetController::class, 'resendOtp']);
Route::post('/forgot-password/reset', [PasswordResetController::class, 'reset']);

// Legacy endpoints (for backward compatibility)
Route::post('/auth/forgot-password', [PasswordResetController::class, 'requestOtp']);
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/sos-requests', [SosRequestController::class, 'index'])
        ->middleware('role:admin');
    Route::patch('/sos-requests/{sosRequest}', [SosRequestController::class, 'update'])
        ->middleware('role:admin');

    Route::prefix('camp')->middleware(['role:campmanager', 'throttle:90,1'])->group(function () {
        Route::get('/context', [EvacuationController::class, 'context']);
        Route::get('/people', [EvacuationController::class, 'search']);
        Route::get('/people/{person}', [EvacuationController::class, 'person']);
        Route::get('/registrations', [EvacuationController::class, 'roster']);
        Route::post('/registrations', [EvacuationController::class, 'register']);
        Route::patch('/registrations/{registration}', [EvacuationController::class, 'move']);
    });
    Route::prefix('admin/evacuation')->middleware('role:admin')->group(function () {
        Route::get('/setup', [EvacuationController::class, 'setup']);
        Route::get('/map', [EvacuationController::class, 'mapCenters']);
        Route::get('/centers', [EvacuationController::class, 'centers']);
        Route::post('/centers', [EvacuationController::class, 'createCenter']);
        Route::get('/centers/{center}', [EvacuationController::class, 'center']);
        Route::patch('/centers/{center}', [EvacuationController::class, 'updateCenter']);
        Route::get('/centers/{center}/registrations', [EvacuationController::class, 'roster']);
        Route::patch('/registrations/{registration}', [EvacuationController::class, 'move']);
        Route::post('/places', [EvacuationController::class, 'place']);
        Route::post('/assignments', [EvacuationController::class, 'assign']);
        Route::post('/events', [EvacuationController::class, 'createEvent']);
        Route::post('/events/{event}/close', [EvacuationController::class, 'closeEvent']);
        Route::get('/residents', [EvacuationController::class, 'residents']);
        Route::post('/residents', [EvacuationController::class, 'linkResident']);
    });
});

// Emergency submissions are intentionally public — reporting an SOS must
// never be blocked by authentication.
Route::post('/sos-requests', [SosRequestController::class, 'store']);

// Facility locations and aggregate occupancy only; no resident or manager data.
Route::get('/evacuation-centers', [EvacuationController::class, 'mapCenters']);
