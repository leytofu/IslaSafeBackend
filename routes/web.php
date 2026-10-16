<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

// Explicit web middleware ensures login/logout always require CSRF protection.
Route::post('/api/auth/web/login', [AuthController::class, 'webLogin'])->middleware('throttle:10,1');
Route::post('/api/auth/web/logout', [AuthController::class, 'webLogout'])->middleware(['auth:web', 'role:admin']);

Route::get('/', function () {
    return view('welcome');
});
