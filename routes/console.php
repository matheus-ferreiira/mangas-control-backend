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

// Sincronização de conteúdos vinculados a usuários (novos eps/caps, status, temporadas).
// DESATIVADO por padrão — descomente e ajuste a frequência conforme sua necessidade.
// O comando respeita rate limit das APIs (AniList ~90/min, TMDb).
// Schedule::command('content:sync-updates')->daily()->withoutOverlapping();

// NOTA: a verificação de novos capítulos (Chapter Tracker) é feita CLIENT-SIDE
// (o app busca a API do site no navegador e envia via POST /user/sync-chapters),
// porque o site bloqueia clientes HTTP server-side. O comando content:check-chapters
// continua existindo, mas NÃO é agendado.
