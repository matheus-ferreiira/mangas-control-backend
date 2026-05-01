<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\ContentRequestController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UserContentController;
use App\Http\Controllers\UserSiteController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Auth público
Route::prefix('auth')->group(function () {
    Route::post('/google', [AuthController::class, 'googleLogin']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('contents', ContentController::class);
    Route::apiResource('sites', SiteController::class);

    // Sites por usuário
    Route::prefix('user-sites')->group(function () {
        Route::get('/', [UserSiteController::class, 'index']);
        Route::post('/', [UserSiteController::class, 'store']);
        Route::get('/{id}', [UserSiteController::class, 'show']);
        Route::put('/{user_site}', [UserSiteController::class, 'update']);
        Route::delete('/{id}', [UserSiteController::class, 'destroy']);
    });

    Route::prefix('user-contents')->group(function () {
        Route::get('/', [UserContentController::class, 'index']);
        Route::post('/', [UserContentController::class, 'store']);
        Route::get('/{id}', [UserContentController::class, 'show']);
        Route::patch('/{id}', [UserContentController::class, 'update']);
        Route::patch('/{id}/increment', [UserContentController::class, 'increment']);
        Route::delete('/{id}', [UserContentController::class, 'destroy']);
    });

    // Solicitações de conteúdo
    Route::prefix('content-requests')->group(function () {
        Route::post('/', [ContentRequestController::class, 'store']);
        Route::get('/my', [ContentRequestController::class, 'myRequests']);

        // Rotas exclusivas para admin
        Route::middleware('admin')->group(function () {
            Route::get('/', [ContentRequestController::class, 'index']);
            Route::patch('/{id}/approve', [ContentRequestController::class, 'approve']);
            Route::patch('/{id}/reject', [ContentRequestController::class, 'reject']);
        });
    });
});

Route::get('/docs', function () {
    return view('swagger');
});

Route::get('/logs', [LogController::class, 'index']);

Route::get('/db-test', function () {
    try {
        DB::connection()->getPdo();
        return 'DB OK';
    } catch (\Exception $e) {
        return $e->getMessage();
    }
});
