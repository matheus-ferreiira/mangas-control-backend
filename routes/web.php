<?php

use App\Http\Controllers\NovelReaderController;
use Illuminate\Support\Facades\Route;

Route::get('/read/{slug}/{chapter}', [NovelReaderController::class, 'browser'])
    ->whereNumber('chapter')->middleware(['signed:relative', 'throttle:120,1'])->name('novel.read');

Route::get('/', function () {
    return response()->json(['status' => 'ok']);
});
