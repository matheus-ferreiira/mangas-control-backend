<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Em 2026-10-20 um `php artisan test` com o .env do Railway rodou
        // migrate:fresh na produção e apagou tudo. Bloqueia migrate:fresh,
        // migrate:refresh, migrate:reset e db:wipe em produção ou contra o Railway.
        DB::prohibitDestructiveCommands($this->app->isProduction() || $this->usesRailwayDatabase());
    }

    private function usesRailwayDatabase(): bool
    {
        $db = config('database.connections.'.config('database.default'), []);

        return str_contains((string) ($db['url'] ?? '').(string) ($db['host'] ?? ''), 'rlwy.net');
    }
}
