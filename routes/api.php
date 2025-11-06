<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SeoController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes with Sanctum + Rate Limiting
Route::middleware(['auth:sanctum', 'throttle:seo'])->group(function () {
    Route::post('/seo/analyze', [SeoController::class, 'analyze']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
