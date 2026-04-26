<?php

use App\Http\Controllers\LogViewerController;
use Illuminate\Support\Facades\Route;

Route::middleware('log.viewer')->group(function () {
    Route::get('/logs', [LogViewerController::class, 'index'])->name('logs.index');
    Route::delete('/logs/all', [LogViewerController::class, 'destroyAll'])->name('logs.clear-all');
    Route::delete('/logs/{file}', [LogViewerController::class, 'destroy'])->name('logs.clear');
});
