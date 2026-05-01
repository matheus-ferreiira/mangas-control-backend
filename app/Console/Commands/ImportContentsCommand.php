<?php

namespace App\Console\Commands;

use App\Services\ExternalContentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ImportContentsCommand extends Command
{
    protected $signature = 'contents:import
                            {--type=          : Tipo: anime, manga, movie, tv (omita para importar tudo)}
                            {--origin=        : Filtrar por origem (manga, manhwa, manhua) — aplica-se apenas ao tipo manga}
                            {--priority=      : Priorizar por origem (manga, manhwa, manhua) — importa tudo, mas itens da origem escolhida primeiro}
                            {--pageStart=1    : Página inicial}
                            {--pageEnd=5      : Página final}
                            {--perPage=25     : Itens por página — Jikan max 25; ignorado na TMDb (fixo em 20)}
                            {--auto           : Continua a partir da última página salva por tipo}
                            {--force          : Atualiza registros já existentes com novos dados da API}
                            {--details        : Busca detalhes adicionais da TMDb por item (mais lento; preenche duration, total_units, trailer, status real)}';

    protected $description = 'Importa conteúdos de APIs externas (Jikan e TMDb) para a tabela contents';

    private const CACHE_KEY = 'contents.import.last_page.';

    public function handle(ExternalContentService $service): int
    {
        $type        = $this->option('type');
        $origin      = $this->option('origin') ?: null;
        $priority    = $this->option('priority') ?: null;
        $pageStart   = (int) $this->option('pageStart');
        $pageEnd     = (int) $this->option('pageEnd');
        $perPage     = min((int) $this->option('perPage'), 25);
        $auto        = (bool) $this->option('auto');
        $force       = (bool) $this->option('force');
        $withDetails = (bool) $this->option('details');

        $validOrigins = ['manga', 'manhwa', 'manhua'];

        if ($type && ! in_array($type, ['anime', 'manga', 'movie', 'tv'])) {
            $this->error("Tipo inválido: \"{$type}\". Use: anime, manga, movie ou tv.");

            return Command::FAILURE;
        }

        if ($origin && ! in_array($origin, $validOrigins)) {
            $this->error("Origem inválida: \"{$origin}\". Use: manga, manhwa ou manhua.");

            return Command::FAILURE;
        }

        if ($priority && ! in_array($priority, $validOrigins)) {
            $this->error("Prioridade inválida: \"{$priority}\". Use: manga, manhwa ou manhua.");

            return Command::FAILURE;
        }

        if (($origin || $priority) && $type && $type !== 'manga') {
            $this->warn("--origin e --priority só se aplicam ao tipo manga; serão ignorados para \"{$type}\".");
        }

        if ($pageStart < 1 || $pageEnd < $pageStart) {
            $this->error('Intervalo inválido: pageStart deve ser >= 1 e pageEnd >= pageStart.');

            return Command::FAILURE;
        }

        if ($force) {
            $this->warn('Modo --force ativo: registros existentes serão atualizados com dados da API.');
        }

        if ($withDetails) {
            $this->warn('Modo --details ativo: uma requisição extra por item da TMDb (mais lento).');
        }

        if ($origin) {
            $this->info("[INFO] Filtro ativo: origin={$origin}");
        }

        if ($priority) {
            $this->info("[INFO] Prioridade ativa: {$priority}");
        }

        $log   = fn (string $message) => $this->line($message);
        $types = $type ? [$type] : ['anime', 'manga', 'movie', 'tv'];
        $total = 0;

        foreach ($types as $t) {
            [$start, $end] = $this->resolvePageRange($t, $pageStart, $pageEnd, $auto);

            $this->info('');
            $this->info($this->sectionHeader($t, $start, $end, $perPage));

            $imported = match ($t) {
                'anime' => $service->importAnime($log, $start, $end, $perPage, $force, $withDetails),
                'manga' => $service->importManga($log, $start, $end, $perPage, $force, $withDetails, $origin, $priority),
                'movie' => $service->importMovies($log, $start, $end, $force, $withDetails),
                'tv'    => $service->importTV($log, $start, $end, $force, $withDetails),
            };

            if ($auto) {
                Cache::forever(self::CACHE_KEY . $t, $end);
                $this->line("[AUTO] Progresso de {$t} salvo até a página {$end}.");
            }

            $this->line("      Inseridos/atualizados neste bloco: {$imported}");
            $total += $imported;
        }

        $this->info('');
        $this->info("Importação concluída. Total inserido/atualizado: {$total}");

        return Command::SUCCESS;
    }

    private function resolvePageRange(string $type, int $pageStart, int $pageEnd, bool $auto): array
    {
        if (! $auto) {
            return [$pageStart, $pageEnd];
        }

        $lastPage   = (int) Cache::get(self::CACHE_KEY . $type, 0);
        $windowSize = $pageEnd - $pageStart + 1;
        $start      = $lastPage + 1;
        $end        = $lastPage + $windowSize;

        $this->line("[AUTO] {$type}: última importada = pág {$lastPage} → próximo bloco = {$start}-{$end}");

        return [$start, $end];
    }

    private function sectionHeader(string $type, int $start, int $end, int $perPage): string
    {
        $labels = [
            'anime' => 'Animes (Jikan)',
            'manga' => 'Mangas (Jikan)',
            'movie' => 'Filmes (TMDb /discover)',
            'tv'    => 'Séries (TMDb /discover)',
        ];

        $extra = in_array($type, ['anime', 'manga']) ? ", {$perPage}/pág" : ', 20/pág fixo';

        return "=== Importando {$labels[$type]} [páginas {$start}–{$end}{$extra}] ===";
    }
}
