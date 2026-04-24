<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UserContentController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/auth/google', [AuthController::class, 'googleLogin']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('contents', ContentController::class);
    Route::apiResource('sites', SiteController::class);

    Route::prefix('user-contents')->group(function () {
        Route::get('/', [UserContentController::class, 'index']);
        Route::post('/', [UserContentController::class, 'store']);
        Route::get('/{id}', [UserContentController::class, 'show']);
        Route::patch('/{id}', [UserContentController::class, 'update']);
        Route::patch('/{id}/increment', [UserContentController::class, 'increment']);
        Route::delete('/{id}', [UserContentController::class, 'destroy']);
    });
});

Route::get('/docs', function () {
    return view('swagger');
});

Route::get('/db-test', function () {
    try {
        DB::connection()->getPdo();
        return 'DB OK';
    } catch (\Exception $e) {
        return $e->getMessage();
    }
});
