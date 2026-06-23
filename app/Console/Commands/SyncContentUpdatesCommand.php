<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\AniListClient;
use App\Services\AniListContentService;
use App\Services\ExternalContentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verifica conteúdos vinculados a usuários e detecta mudanças nos campos críticos
 * (novos episódios/capítulos, mudança de status, nova temporada, término, banner/capa).
 * Cada mudança é aplicada em `contents` e registrada em `content_updates_log`.
 */
class SyncContentUpdatesCommand extends Command
{
    protected $signature = 'content:sync-updates
                            {--type=    : Limita a um tipo: anime, manga, movie, tv}
                            {--dry-run  : Mostra o que mudaria sem salvar nada}';

    protected $description = 'Atualiza conteúdos vinculados a usuários a partir das APIs externas';

    /** Campos comparados entre o banco e a API. */
    private const CRITICAL_FIELDS = ['total_units', 'status', 'total_seasons', 'end_date', 'banner_image', 'cover'];

    public function handle(
        AniListClient $aniListClient,
        AniListContentService $aniList,
        ExternalContentService $tmdb
    ): int {
        $type = $this->option('type');
        $dryRun = (bool) $this->option('dry-run');

        if ($type && ! in_array($type, ['anime', 'manga', 'movie', 'tv'], true)) {
            $this->error("Tipo inválido: \"{$type}\".");

            return Command::FAILURE;
        }

        // Conteúdos com pelo menos um vínculo em user_contents (DISTINCT content_id)
        $query = Content::query()
            ->whereIn('id', DB::table('user_contents')->distinct()->pluck('content_id'));

        if ($type) {
            $query->where('type', $type);
        }

        $contents = $query->get();

        if ($contents->isEmpty()) {
            $this->info('Nenhum conteúdo vinculado a usuários para verificar.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Modo --dry-run: nenhuma alteração será salva.');
        }

        $checked = 0;
        $applied = 0;
        $changeLines = [];

        foreach ($contents as $content) {
            $checked++;

            try {
                $normalized = $this->fetchNormalized($content, $aniListClient, $aniList, $tmdb, $source);
            } catch (\Throwable $e) {
                $this->line("[ERRO] {$content->name}: ".$e->getMessage());
                continue;
            }

            if ($normalized === null) {
                $this->line("[SKIP] {$content->name} — sem ID de API utilizável.");
                continue;
            }

            $changes = $this->diffCriticalFields($content, $normalized);

            foreach ($changes as $field => [$old, $new]) {
                $changeLines[] = "[{$content->name}] {$field}: ".($old ?? 'null')." → {$new}";

                if (! $dryRun) {
                    $content->update([$field => $new]);
                    DB::table('content_updates_log')->insert([
                        'content_id' => $content->id,
                        'field_changed' => $field,
                        'old_value' => $old !== null ? (string) $old : null,
                        'new_value' => (string) $new,
                        'source' => $source,
                        'checked_at' => now(),
                        'created_at' => now(),
                    ]);
                }

                $applied++;
            }

            // Rate limiting entre chamadas
            $this->throttle($content->type);
        }

        $this->info('');
        $this->info("✅ {$checked} conteúdos verificados, {$applied} atualizações ".($dryRun ? 'detectadas (dry-run)' : 'aplicadas').'.');

        foreach ($changeLines as $line) {
            $this->line('  '.$line);
        }

        return Command::SUCCESS;
    }

    /**
     * Busca o item na API correta e normaliza. Define $source por referência.
     */
    private function fetchNormalized(
        Content $content,
        AniListClient $aniListClient,
        AniListContentService $aniList,
        ExternalContentService $tmdb,
        ?string &$source
    ): ?array {
        if (in_array($content->type, ['anime', 'manga'], true)) {
            $source = 'anilist';
            $aniListType = $content->type === 'manga' ? 'MANGA' : 'ANIME';

            $item = null;
            if ($content->anilist_id) {
                $item = $aniListClient->fetchById((int) $content->anilist_id);
            } elseif ($content->mal_id) {
                $item = $aniListClient->fetchByMalId((int) $content->mal_id, $aniListType);
            }

            return $item ? $aniList->normalizeAniListItem($item, $content->type) : null;
        }

        // movie / tv → TMDb
        $source = 'tmdb';

        return $content->external_id
            ? $tmdb->fetchForSync($content->external_id, $content->type)
            : null;
    }

    /**
     * Compara os campos críticos. Só considera mudança quando o valor novo é
     * não-nulo e difere do atual (evita apagar dados quando a API devolve null).
     *
     * @return array<string, array{0: mixed, 1: mixed}>  field => [old, new]
     */
    private function diffCriticalFields(Content $content, array $normalized): array
    {
        $changes = [];

        foreach (self::CRITICAL_FIELDS as $field) {
            if (! array_key_exists($field, $normalized)) {
                continue;
            }

            $new = $normalized[$field];
            if ($new === null) {
                continue;
            }

            $old = $content->getAttribute($field);

            // Normaliza datas para comparação Y-m-d
            $oldCmp = $field === 'end_date' && $old ? \Carbon\Carbon::parse($old)->format('Y-m-d') : $old;
            $newCmp = $field === 'end_date' && $new ? \Carbon\Carbon::parse($new)->format('Y-m-d') : $new;

            if ((string) $oldCmp !== (string) $newCmp) {
                $changes[$field] = [$oldCmp, $newCmp];
            }
        }

        return $changes;
    }

    private function throttle(string $type): void
    {
        if (in_array($type, ['anime', 'manga'], true)) {
            usleep(700_000); // AniList: < 90/min
        } else {
            usleep(300_000); // TMDb
        }
    }
}
