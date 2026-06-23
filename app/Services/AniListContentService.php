<?php

namespace App\Services;

use App\Helpers\NameHelper;
use App\Models\Content;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Importação e normalização de anime/mangá a partir da AniList (fonte primária).
 *
 * Substitui o caminho Jikan que ficava no ExternalContentService. A detecção de
 * origem (manga/manhwa/manhua) agora usa `countryOfOrigin` — a heurística antiga
 * de Unicode/publishers foi REMOVIDA.
 *
 * O Jikan permanece apenas como fallback cirúrgico (ver enrichFromJikan) para
 * `age_rating` e `trailer_url` (e, de quebra, `mal_rank`/`mal_popularity_rank`),
 * que a AniList não fornece.
 */
class AniListContentService
{
    // Campos que --force nunca sobrescreve (identidade do registro)
    private const FORCE_SKIP = ['name', 'alternative_names', 'type', 'source', 'external_id', 'anilist_id', 'mal_id'];

    public function __construct(private AniListClient $client) {}

    /**
     * Importa N páginas (50 itens cada) de um tipo AniList.
     *
     * @param  string  $aniListType  'ANIME' ou 'MANGA'
     * @param  string  $contentType  'anime' ou 'manga'
     * @param  bool  $enrich  Se true, completa age_rating/trailer via Jikan (best-effort).
     */
    public function importMedia(
        callable $log,
        string $aniListType,
        string $contentType,
        int $pages = 1,
        bool $force = false,
        bool $includeAdult = false,
        bool $enrich = true,
        ?string $countryOfOrigin = null,
        ?string $format = null
    ): int {
        $imported = 0;

        for ($page = 1; $page <= $pages; $page++) {
            try {
                $media = $this->client->fetchPage($aniListType, $page, $includeAdult, $countryOfOrigin, $format);
            } catch (\Throwable $e) {
                $log("[AVISO] AniList página {$page}: ".$e->getMessage());
                break;
            }

            if (empty($media)) {
                break;
            }

            foreach ($media as $item) {
                $name = trim($item['title']['english'] ?? $item['title']['romaji'] ?? '');
                if (! $name) {
                    continue;
                }

                try {
                    $data = $this->normalizeAniListItem($item, $contentType);

                    // Fallback Jikan (best-effort): só para anime e quando há MAL ID.
                    if ($enrich && $contentType === 'anime' && ! empty($data['mal_id'])) {
                        $this->enrichFromJikan($data, (int) $data['mal_id']);
                    }

                    $imported += $this->upsert($data, $force, $log);
                } catch (\Throwable $e) {
                    $log("[ERRO ITEM][{$contentType}] {$name}: ".$e->getMessage());
                    Log::warning('AniList import item error', [
                        'type' => $contentType,
                        'name' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Rate limit AniList: < 90/min (e tolera o modo degradado de 30/min).
            if ($page < $pages) {
                usleep(700_000);
            }
        }

        return $imported;
    }

    /**
     * Mapeia um item da AniList para o formato da tabela `contents`.
     */
    public function normalizeAniListItem(array $item, string $contentType): array
    {
        $country = $item['countryOfOrigin'] ?? 'JP';
        $tags = $item['tags'] ?? [];

        // themes: tags não-Genre e não-spoiler. demographics: tags de categoria Demographic.
        // NOTA: por seguir o mapeamento pedido, tags Demographic também aparecem em `themes`
        // (categoria != 'Genre'); é uma pequena sobreposição aceita conscientemente.
        $themes = collect($tags)
            ->filter(fn ($t) => ($t['category'] ?? '') !== 'Genre' && ! ($t['isGeneralSpoiler'] ?? false))
            ->pluck('name')->filter()->values()->all();

        $demographics = collect($tags)
            ->filter(fn ($t) => ($t['category'] ?? '') === 'Demographic')
            ->pluck('name')->filter()->values()->all();

        $studios = collect($item['studios']['nodes'] ?? [])
            ->filter(fn ($s) => $s['isAnimationStudio'] ?? false)
            ->pluck('name')->filter()->values()->all();

        // averageScore (0-100) → 0-10. null se ausente (evita gravar 0.0 falso).
        $avg = $item['averageScore'] ?? null;
        $rating = $avg !== null ? round($avg / 10, 2) : null;

        $altNames = NameHelper::normalizeList(array_merge(
            array_filter([
                $item['title']['romaji'] ?? null,
                $item['title']['english'] ?? null,
                $item['title']['native'] ?? null,
            ]),
            $item['synonyms'] ?? []
        ));

        return [
            // Identificadores
            'anilist_id' => $item['id'] ?? null,
            'mal_id' => $item['idMal'] ?? null,
            'external_id' => isset($item['id']) ? (string) $item['id'] : null, // AniList ID como externo primário
            'source' => 'anilist',

            // Nomes
            'name' => $item['title']['english'] ?? $item['title']['romaji'] ?? null,
            'alternative_names' => $altNames,

            // Classificação / origem
            'type' => $contentType,
            'format' => isset($item['format']) ? strtolower($item['format']) : null,
            'origin_type' => $contentType === 'manga'
                ? match ($country) {
                    'JP' => 'manga',
                    'KR' => 'manhwa',
                    'CN' => 'manhua',
                    default => 'manga',
                }
                : null,
            'origin_source' => $item['source'] ?? null,
            'status' => match ($item['status'] ?? '') {
                'FINISHED' => 'completed',
                'RELEASING' => 'ongoing',
                'NOT_YET_RELEASED' => 'upcoming',
                'CANCELLED' => 'cancelled',
                'HIATUS' => 'hiatus',
                default => null,
            },
            'is_adult' => (bool) ($item['isAdult'] ?? false),
            'age_rating' => null, // AniList não fornece; preenchido via enrichFromJikan (anime)

            // Mídia
            'cover' => $item['coverImage']['extraLarge'] ?? null,
            'banner_image' => $item['bannerImage'] ?? null,
            'trailer_url' => $this->buildTrailerUrl($item['trailer'] ?? null),

            // Conteúdo
            'total_units' => $item['episodes'] ?? $item['chapters'] ?? null,
            'total_seasons' => null,
            'duration' => $item['duration'] ?? null,
            'release_date' => $this->formatDate($item['startDate'] ?? null),
            'end_date' => $this->formatDate($item['endDate'] ?? null),
            'synopsis' => isset($item['description']) ? trim(strip_tags($item['description'])) : null,
            'genres' => $item['genres'] ?? [],
            'studios' => $studios,
            'demographics' => $demographics,
            'themes' => $themes,
            'networks' => null,

            // Origem
            'release_year' => $item['seasonYear'] ?? ($item['startDate']['year'] ?? null),
            'original_language' => match ($country) {
                'JP' => 'ja',
                'KR' => 'ko',
                'CN' => 'zh',
                default => null,
            },
            'country' => $country,

            // Métricas
            'rating' => $rating,
            'votes_count' => null, // AniList não expõe contagem de votos
            // score reaproveita o averageScore da AniList (já ponderado pela plataforma).
            // Mantém a seção top_rated do Discover funcionando (filtra score >= 7.5).
            'score' => $rating,
            'popularity' => $item['popularity'] ?? null, // contagem de usuários (maior = melhor)
            'mal_rank' => null,            // preenchido via enrichFromJikan
            'mal_popularity_rank' => null, // preenchido via enrichFromJikan
        ];
    }

    /**
     * Fallback Jikan (best-effort) — preenche apenas campos faltantes:
     * age_rating, trailer_url e, de quebra, mal_rank/mal_popularity_rank.
     * Nunca sobrescreve valores já preenchidos. Falhas são silenciosas (não
     * quebram o import — os dados da AniList já são suficientes).
     */
    public function enrichFromJikan(array &$content, int $malId): void
    {
        $needs = empty($content['age_rating'])
            || empty($content['trailer_url'])
            || empty($content['mal_rank'])
            || empty($content['mal_popularity_rank']);

        if (! $needs) {
            return;
        }

        $jikanType = ($content['type'] ?? 'anime') === 'manga' ? 'manga' : 'anime';

        try {
            $response = Http::timeout(15)->retry(2, 500)
                ->get("https://api.jikan.moe/v4/{$jikanType}/{$malId}/full");

            if ($response->status() === 429) {
                sleep(60);
                $response = Http::timeout(15)->get("https://api.jikan.moe/v4/{$jikanType}/{$malId}/full");
            }

            if (! $response->successful()) {
                return;
            }

            $d = $response->json('data', []);

            if (empty($content['age_rating']) && ! empty($d['rating'])) {
                $content['age_rating'] = trim(explode(' - ', $d['rating'], 2)[0]) ?: null;
            }

            if (empty($content['trailer_url'])) {
                $content['trailer_url'] = $this->jikanTrailerUrl($d['trailer'] ?? null);
            }

            if (empty($content['mal_rank']) && ! empty($d['rank'])) {
                $content['mal_rank'] = (int) $d['rank'];
            }

            if (empty($content['mal_popularity_rank']) && ! empty($d['popularity'])) {
                $content['mal_popularity_rank'] = (int) $d['popularity'];
            }

            usleep(400_000); // respeita o rate limit do Jikan
        } catch (\Throwable $e) {
            Log::info('enrichFromJikan falhou', ['mal_id' => $malId, 'error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // upsert / dedup
    // ─────────────────────────────────────────────────────────────────────────

    private function upsert(array $data, bool $force, callable $log): int
    {
        $existing = $this->findExisting($data);
        $label = $data['origin_type'] ?? $data['type'];
        $name = $data['name'];

        if ($existing) {
            if (! $force) {
                $log("[SKIP][{$label}] {$name} já existe");

                return 0;
            }

            $existing->update(Arr::except($data, self::FORCE_SKIP));
            $log("[UPDATE][{$label}] {$name} atualizado");

            return 1;
        }

        Content::create($data);
        $log("[OK][{$label}] {$name} inserido");

        return 1;
    }

    private function findExisting(array $data): ?Content
    {
        // 1ª: anilist_id (mais preciso)
        if (! empty($data['anilist_id'])) {
            $r = Content::where('anilist_id', $data['anilist_id'])->first();
            if ($r) {
                return $r;
            }
        }

        // 2ª: mal_id + type
        if (! empty($data['mal_id'])) {
            $r = Content::where('mal_id', $data['mal_id'])->where('type', $data['type'])->first();
            if ($r) {
                return $r;
            }
        }

        // 3ª: nome normalizado + type
        return Content::whereRaw('LOWER(TRIM(name)) = ?', [NameHelper::normalize($data['name'] ?? '')])
            ->where('type', $data['type'])
            ->first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function formatDate(?array $date): ?string
    {
        if (empty($date['year'])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $date['year'], $date['month'] ?? 1, $date['day'] ?? 1);
    }

    /** AniList trailer → URL do YouTube (site == 'youtube'). */
    private function buildTrailerUrl(?array $trailer): ?string
    {
        if ($trailer && ($trailer['site'] ?? '') === 'youtube' && ! empty($trailer['id'])) {
            return 'https://www.youtube.com/watch?v='.$trailer['id'];
        }

        return null;
    }

    /** Jikan trailer → URL do YouTube (youtube_id ou embed_url). */
    private function jikanTrailerUrl(?array $trailer): ?string
    {
        if (! $trailer) {
            return null;
        }

        if (! empty($trailer['youtube_id'])) {
            return 'https://www.youtube.com/watch?v='.$trailer['youtube_id'];
        }

        if (! empty($trailer['embed_url']) && preg_match('#embed/([^?]+)#', $trailer['embed_url'], $m)) {
            return 'https://www.youtube.com/watch?v='.$m[1];
        }

        return null;
    }
}
