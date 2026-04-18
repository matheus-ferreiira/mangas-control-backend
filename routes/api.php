<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MangaController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UserMangaController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/google', [AuthController::class, 'googleLogin']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('mangas', MangaController::class);
    Route::apiResource('sites', SiteController::class);

    Route::prefix('user-mangas')->group(function () {
        Route::get('/', [UserMangaController::class, 'index']);
        Route::post('/', [UserMangaController::class, 'store']);
        Route::get('/{userManga}', [UserMangaController::class, 'show']);
        Route::patch('/{userManga}', [UserMangaController::class, 'update']);
        Route::patch('/{userManga}/increment', [UserMangaController::class, 'increment']);
        Route::delete('/{userManga}', [UserMangaController::class, 'destroy']);
    });
});

Route::get('/docs', function () {
    return view('swagger');
});