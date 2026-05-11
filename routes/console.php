<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Remove entradas expiradas da tabela cache (driver database não faz isso automaticamente).
Schedule::call(function () {
    DB::table('cache')->where('expiration', '<', time())->delete();
})->daily()->name('cache:prune-expired')->withoutOverlapping();
