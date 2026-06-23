<?php

namespace App\Console\Commands;

use App\Services\AniListContentService;
use App\Services\ExternalContentService;
use Illuminate\Console\Command;

/**
 * Importa conteúdos das APIs externas:
 *  - anime / manga → AniList (fonte primária; fallback Jikan p/ age_rating+trailer)
 *  - movie / tv    → TMDb (inalterado)
 */
class ImportContentsCommand extends Command
{
    protected $signature = 'content:import
                            {--type=     : Tipo: anime, manga, movie, tv (omita para importar tudo)}
                            {--pages=1   : Número de páginas a importar (AniList=50/pág, TMDb=20/pág)}
                            {--force     : Atualiza registros já existentes com novos dados da API}
                            {--details   : (TMDb apenas) busca detalhes por item (duration, trailer, status real)}
                            {--adult     : Inclui conteúdo adulto (AniList sem filtro isAdult; TMDb include_adult). Off por padrão}';

    protected $description = 'Importa conteúdos de APIs externas (AniList p/ anime/mangá, TMDb p/ filmes/séries)';

    public function handle(AniListContentService $aniList, ExternalContentService $tmdb): int
    {
        $type = $this->option('type');
        $pages = max(1, (int) $this->option('pages'));
        $force = (bool) $this->option('force');
        $details = (bool) $this->option('details');
        $adult = (bool) $this->option('adult');

        if ($type && ! in_array($type, ['anime', 'manga', 'movie', 'tv'], true)) {
            $this->error("Tipo inválido: \"{$type}\". Use: anime, manga, movie ou tv.");

            return Command::FAILURE;
        }

        if ($force) {
            $this->warn('Modo --force ativo: registros existentes serão atualizados.');
        }

        if ($adult) {
            $this->warn('Modo --adult ativo: conteúdo adulto será incluído.');
        }

        $log = fn (string $message) => $this->line($message);
        $types = $type ? [$type] : ['anime', 'manga', 'movie', 'tv'];
        $total = 0;

        foreach ($types as $t) {
            $this->info('');
            $this->info("=== Importando {$t} [{$pages} pág] ===");

            $imported = match ($t) {
                'anime' => $aniList->importMedia($log, 'ANIME', 'anime', $pages, $force, $adult),
                'manga' => $aniList->importMedia($log, 'MANGA', 'manga', $pages, $force, $adult),
                'movie' => $tmdb->importMovies($log, 1, $pages, $force, $details, $adult),
                'tv' => $tmdb->importTV($log, 1, $pages, $force, $details, $adult),
            };

            $this->line("      Inseridos/atualizados: {$imported}");
            $total += $imported;
        }

        $this->info('');
        $this->info("Importação concluída. Total inserido/atualizado: {$total}");

        return Command::SUCCESS;
    }
}
