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
                            {--adult     : Inclui conteúdo adulto (AniList sem filtro isAdult; TMDb include_adult). Off por padrão}
                            {--origin=   : (manga) origem via countryOfOrigin: manga=JP, manhwa=KR, manhua=CN}
                            {--format=   : (manga) MediaFormat AniList: MANGA, NOVEL, ONE_SHOT (NOVEL salva type=novel)}';

    protected $description = 'Importa conteúdos de APIs externas (AniList p/ anime/mangá, TMDb p/ filmes/séries)';

    public function handle(AniListContentService $aniList, ExternalContentService $tmdb): int
    {
        $type = $this->option('type');
        $pages = max(1, (int) $this->option('pages'));
        $force = (bool) $this->option('force');
        $details = (bool) $this->option('details');
        $adult = (bool) $this->option('adult');
        $origin = $this->option('origin');
        $format = $this->option('format');
        $format = $format !== null ? strtoupper($format) : null;

        if ($type && ! in_array($type, ['anime', 'manga', 'movie', 'tv'], true)) {
            $this->error("Tipo inválido: \"{$type}\". Use: anime, manga, movie ou tv.");

            return Command::FAILURE;
        }

        // --origin → countryOfOrigin da AniList (só manga)
        $originCountry = null;
        if ($origin !== null) {
            $map = ['manga' => 'JP', 'manhwa' => 'KR', 'manhua' => 'CN'];
            if (! isset($map[$origin])) {
                $this->error("Origin inválida: \"{$origin}\". Use: manga, manhwa ou manhua.");

                return Command::FAILURE;
            }
            $originCountry = $map[$origin];
        }

        // --format → MediaFormat da AniList (manhwa/manhua NÃO são format; use --origin)
        if ($format !== null && ! in_array($format, ['MANGA', 'NOVEL', 'ONE_SHOT'], true)) {
            $this->error("Format inválido: \"{$format}\". Use: MANGA, NOVEL ou ONE_SHOT. (Para manhwa/manhua use --origin.)");

            return Command::FAILURE;
        }

        if (($origin !== null || $format !== null) && $type && $type !== 'manga') {
            $this->warn('--origin/--format aplicam-se apenas a --type=manga; ignorados nos demais tipos.');
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
                'manga' => $aniList->importMedia(
                    $log, 'MANGA',
                    $format === 'NOVEL' ? 'novel' : 'manga',
                    $pages, $force, $adult, true,
                    $originCountry, $format
                ),
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
