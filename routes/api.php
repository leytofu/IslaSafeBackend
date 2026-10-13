<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\SosRequestController;
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
        ->middleware('role:admin,campmanager');
    Route::patch('/sos-requests/{sosRequest}', [SosRequestController::class, 'update'])
        ->middleware('role:admin,campmanager');
});

// Emergency submissions are intentionally public — reporting an SOS must
// never be blocked by authentication.
Route::post('/sos-requests', [SosRequestController::class, 'store']);
