<?php

namespace App\Services;

use App\Helpers\NameHelper;
use App\Models\Content;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExternalContentService
{
    private const JIKAN_BASE      = 'https://api.jikan.moe/v4';
    private const TMDB_BASE       = 'https://api.themoviedb.org/3';
    private const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p/w500';
    private const TMDB_BACK_BASE  = 'https://image.tmdb.org/t/p/original';

    // Campos que --force nunca sobrescreve (identidade do registro)
    private const FORCE_SKIP = ['name', 'alternative_names', 'type', 'source', 'external_id'];

    // ─────────────────────────────────────────────────────────────────────────
    // Métodos públicos de importação — processam página a página para:
    //  • Logar o número da página em caso de erro por item
    //  • Reduzir uso de memória em importações grandes
    // ─────────────────────────────────────────────────────────────────────────

    public function importAnime(
        callable $log,
        int $pageStart    = 1,
        int $pageEnd      = 5,
        int $perPage      = 25,
        bool $force       = false,
        bool $withDetails = false
    ): int {
        $imported = 0;

        for ($page = $pageStart; $page <= $pageEnd; $page++) {
            $result = $this->fetchJikanPage('/top/anime', $log, $page, $perPage);

            if (empty($result['data'])) {
                break;
            }

            foreach ($result['data'] as $item) {
                $name = trim($item['title'] ?? '');
                if (! $name) {
                    continue;
                }

                try {
                    $rating     = isset($item['score']) ? (float) $item['score'] : null;
                    $votesCount = $item['scored_by'] ?? null;

                    $data = [
                        'external_id'       => (string) ($item['mal_id'] ?? ''),
                        'source'            => 'jikan',
                        'name'              => $name,
                        'alternative_names' => $this->extractAltNames($item, $name),
                        'cover'             => $item['images']['jpg']['large_image_url'] ?? null,
                        'background'        => null,
                        'type'              => 'anime',
                        'status'            => $this->mapJikanStatus($item['status'] ?? ''),
                        'is_adult'          => ($item['rating'] ?? '') === 'Rx - Hentai',
                        'total_units'       => $item['episodes'] ?? null,
                        'total_seasons'     => null,
                        'duration'          => $this->parseJikanDuration($item['duration'] ?? null),
                        'last_unit_update'  => $this->parseDate($item['aired']['from'] ?? null),
                        'trailer_url'       => $item['trailer']['url'] ?? null,
                        'rating'            => $rating,
                        'popularity'        => $item['popularity'] ?? null,
                        'votes_count'       => $votesCount,
                        'score'             => $this->calculateScore($rating, $votesCount),
                        'synopsis'          => $item['synopsis'] ?? null,
                        'genres'            => $this->extractJikanGenres($item),
                        'release_year'      => $this->cleanYear($item['year'] ?? null),
                        'original_language' => 'ja',
                        'country'           => 'JP',
                    ];

                    $imported += $this->upsert($data, $data['alternative_names'], $force, $log, 'anime');
                } catch (\Exception $e) {
                    $log("[ERRO ITEM][anime][pág {$page}] {$name}: " . $e->getMessage());
                    Log::warning('ImportContents item error', [
                        'type'  => 'anime',
                        'page'  => $page,
                        'name'  => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! $result['has_next']) {
                break;
            }

            if ($page < $pageEnd) {
                usleep(500_000); // 500ms → ~2 req/s
            }
        }

        return $imported;
    }

    public function importManga(
        callable $log,
        int $pageStart    = 1,
        int $pageEnd      = 5,
        int $perPage      = 25,
        bool $force       = false,
        bool $withDetails = false,
        ?string $origin   = null,
        ?string $priority = null,
    ): int {
        $imported = 0;

        for ($page = $pageStart; $page <= $pageEnd; $page++) {
            $result = $this->fetchJikanPage('/top/manga', $log, $page, $perPage);

            if (empty($result['data'])) {
                break;
            }

            // Prioridade: itens da origem preferida sobem para o topo da página
            $items = $result['data'];
            if ($priority) {
                $priorityItems = array_values(array_filter($items, fn ($i) => $this->detectOriginType($i) === $priority));
                $normalItems   = array_values(array_filter($items, fn ($i) => $this->detectOriginType($i) !== $priority));
                $items         = array_merge($priorityItems, $normalItems);
            }

            foreach ($items as $item) {
                $name = trim($item['title'] ?? '');
                if (! $name) {
                    continue;
                }

                try {
                    $originType = $this->detectOriginType($item);

                    // Filtro: pula itens que não pertencem à origem solicitada
                    if ($origin && $originType !== $origin) {
                        $log("[SKIP][origin] {$name} (detectado: {$originType}, esperado: {$origin})");
                        continue;
                    }

                    $rating     = isset($item['score']) ? (float) $item['score'] : null;
                    $votesCount = $item['scored_by'] ?? null;

                    $data = [
                        'external_id'       => (string) ($item['mal_id'] ?? ''),
                        'source'            => 'jikan',
                        'name'              => $name,
                        'alternative_names' => $this->extractAltNames($item, $name),
                        'origin_type'       => $originType,
                        'cover'             => $item['images']['jpg']['large_image_url'] ?? null,
                        'background'        => null,
                        'type'              => 'manga',
                        'status'            => $this->mapJikanStatus($item['status'] ?? ''),
                        'is_adult'          => false,
                        'total_units'       => $item['chapters'] ?? null,
                        'total_seasons'     => null,
                        'duration'          => null,
                        'last_unit_update'  => $this->parseDate($item['published']['from'] ?? null),
                        'trailer_url'       => null,
                        'rating'            => $rating,
                        'popularity'        => $item['popularity'] ?? null,
                        'votes_count'       => $votesCount,
                        'score'             => $this->calculateScore($rating, $votesCount),
                        'synopsis'          => $item['synopsis'] ?? null,
                        'genres'            => $this->extractJikanGenres($item),
                        'release_year'      => $this->cleanYear(
                            $item['year'] ?? $this->extractYearFromDate($item['published']['from'] ?? null)
                        ),
                        'original_language' => 'ja',
                        'country'           => 'JP',
                    ];

                    $imported += $this->upsert($data, $data['alternative_names'], $force, $log, 'manga');
                } catch (\Exception $e) {
                    $log("[ERRO ITEM][manga][pág {$page}] {$name}: " . $e->getMessage());
                    Log::warning('ImportContents item error', [
                        'type'  => 'manga',
                        'page'  => $page,
                        'name'  => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! $result['has_next']) {
                break;
            }

            if ($page < $pageEnd) {
                usleep(500_000);
            }
        }

        return $imported;
    }

    public function importMovies(
        callable $log,
        int $pageStart    = 1,
        int $pageEnd      = 5,
        bool $force       = false,
        bool $withDetails = false
    ): int {
        $apiKey = config('services.tmdb.key');

        if (! $apiKey) {
            $log('[ERRO] TMDB_API_KEY não configurada. Adicione ao .env e execute novamente.');

            return 0;
        }

        $genreMap = $this->getTmdbGenreMap($apiKey, 'movie');
        $imported = 0;

        for ($page = $pageStart; $page <= $pageEnd; $page++) {
            $result = $this->fetchTmdbPage('/discover/movie', $apiKey, $log, $page);

            if (empty($result['data'])) {
                break;
            }

            foreach ($result['data'] as $item) {
                $name = trim($item['title'] ?? '');
                if (! $name) {
                    continue;
                }

                try {
                    $detail = [];
                    if ($withDetails && ! empty($item['id'])) {
                        $detail = $this->fetchTmdbDetails('movie', (int) $item['id'], $apiKey);
                        usleep(150_000);
                    }

                    $rating     = isset($item['vote_average']) ? (float) $item['vote_average'] : null;
                    $votesCount = $item['vote_count'] ?? null;

                    $data = [
                        'external_id'       => (string) $item['id'],
                        'source'            => 'tmdb',
                        'name'              => $name,
                        'alternative_names' => $this->extractTmdbAltNames($item, $name),
                        'cover'             => $this->tmdbImage($item['poster_path'] ?? null),
                        'background'        => $this->tmdbBackdrop($item['backdrop_path'] ?? null),
                        'type'              => 'movie',
                        'status'            => 'completed',
                        'is_adult'          => (bool) ($item['adult'] ?? false),
                        'total_units'       => null,
                        'total_seasons'     => null,
                        'duration'          => $detail ? ($detail['runtime'] ?? null) : null,
                        'last_unit_update'  => $this->parseDate($item['release_date'] ?? null),
                        'trailer_url'       => $detail ? $this->extractTmdbTrailer($detail) : null,
                        'rating'            => $rating,
                        'popularity'        => isset($item['popularity']) ? (int) $item['popularity'] : null,
                        'votes_count'       => $votesCount,
                        'score'             => $this->calculateScore($rating, $votesCount),
                        'synopsis'          => $item['overview'] ?? null,
                        'genres'            => $this->mapTmdbGenres($item['genre_ids'] ?? [], $genreMap),
                        'release_year'      => $this->extractYearFromDate($item['release_date'] ?? null),
                        'original_language' => $item['original_language'] ?? null,
                        'country'           => $detail
                            ? ($detail['production_countries'][0]['iso_3166_1'] ?? null)
                            : null,
                    ];

                    $imported += $this->upsert($data, [], $force, $log, 'movie');
                } catch (\Exception $e) {
                    $log("[ERRO ITEM][movie][pág {$page}] {$name}: " . $e->getMessage());
                    Log::warning('ImportContents item error', [
                        'type'  => 'movie',
                        'page'  => $page,
                        'name'  => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($page >= $result['total_pages']) {
                break;
            }

            if ($page < $pageEnd) {
                usleep(200_000);
            }
        }

        return $imported;
    }

    public function importTV(
        callable $log,
        int $pageStart    = 1,
        int $pageEnd      = 5,
        bool $force       = false,
        bool $withDetails = false
    ): int {
        $apiKey = config('services.tmdb.key');

        if (! $apiKey) {
            $log('[ERRO] TMDB_API_KEY não configurada. Adicione ao .env e execute novamente.');

            return 0;
        }

        $genreMap = $this->getTmdbGenreMap($apiKey, 'tv');
        $imported = 0;

        for ($page = $pageStart; $page <= $pageEnd; $page++) {
            $result = $this->fetchTmdbPage('/discover/tv', $apiKey, $log, $page);

            if (empty($result['data'])) {
                break;
            }

            foreach ($result['data'] as $item) {
                $name = trim($item['name'] ?? '');
                if (! $name) {
                    continue;
                }

                try {
                    $detail = [];
                    if ($withDetails && ! empty($item['id'])) {
                        $detail = $this->fetchTmdbDetails('tv', (int) $item['id'], $apiKey);
                        usleep(150_000);
                    }

                    $rating     = isset($item['vote_average']) ? (float) $item['vote_average'] : null;
                    $votesCount = $item['vote_count'] ?? null;

                    $status = $detail
                        ? $this->mapTmdbTvStatus($detail['status'] ?? '')
                        : $this->inferTvStatus($item['first_air_date'] ?? null);

                    $data = [
                        'external_id'       => (string) $item['id'],
                        'source'            => 'tmdb',
                        'name'              => $name,
                        'alternative_names' => $this->extractTmdbAltNames($item, $name),
                        'cover'             => $this->tmdbImage($item['poster_path'] ?? null),
                        'background'        => $this->tmdbBackdrop($item['backdrop_path'] ?? null),
                        'type'              => 'tv',
                        'status'            => $status,
                        'is_adult'          => (bool) ($item['adult'] ?? false),
                        'total_units'       => $detail ? ($detail['number_of_episodes'] ?? null) : null,
                        'total_seasons'     => $detail ? ($detail['number_of_seasons'] ?? null) : null,
                        'duration'          => $detail ? ($detail['episode_run_time'][0] ?? null) : null,
                        'last_unit_update'  => $this->parseDate($item['first_air_date'] ?? null),
                        'trailer_url'       => $detail ? $this->extractTmdbTrailer($detail) : null,
                        'rating'            => $rating,
                        'popularity'        => isset($item['popularity']) ? (int) $item['popularity'] : null,
                        'votes_count'       => $votesCount,
                        'score'             => $this->calculateScore($rating, $votesCount),
                        'synopsis'          => $item['overview'] ?? null,
                        'genres'            => $this->mapTmdbGenres($item['genre_ids'] ?? [], $genreMap),
                        'release_year'      => $this->extractYearFromDate($item['first_air_date'] ?? null),
                        'original_language' => $item['original_language'] ?? null,
                        'country'           => $item['origin_country'][0]
                            ?? ($detail['origin_country'][0] ?? null),
                    ];

                    $imported += $this->upsert($data, [], $force, $log, 'tv');
                } catch (\Exception $e) {
                    $log("[ERRO ITEM][tv][pág {$page}] {$name}: " . $e->getMessage());
                    Log::warning('ImportContents item error', [
                        'type'  => 'tv',
                        'page'  => $page,
                        'name'  => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($page >= $result['total_pages']) {
                break;
            }

            if ($page < $pageEnd) {
                usleep(200_000);
            }
        }

        return $imported;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lógica de insert / update (upsert)
    // ─────────────────────────────────────────────────────────────────────────

    private function upsert(array $data, array $altNames, bool $force, callable $log, string $type): int
    {
        $name     = $data['name'];
        $source   = $data['source'] ?? null;
        $extId    = $data['external_id'] ?? null;
        $label    = $data['origin_type'] ?? $type;
        $existing = $this->findExisting($name, $altNames, $source, $extId);

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

    // ─────────────────────────────────────────────────────────────────────────
    // Deduplicação com 3 níveis de prioridade
    // ─────────────────────────────────────────────────────────────────────────

    private function findExisting(
        string $name,
        array $altNames,
        ?string $source,
        ?string $externalId
    ): ?Content {
        // 1ª prioridade: source + external_id (lookup por índice — mais rápido e preciso)
        if ($source && $externalId) {
            $record = Content::where('source', $source)
                ->where('external_id', $externalId)
                ->first();

            if ($record) {
                return $record;
            }
        }

        // 2ª prioridade: nome normalizado
        $record = Content::whereRaw('LOWER(TRIM(name)) = ?', [NameHelper::normalize($name)])->first();
        if ($record) {
            return $record;
        }

        // 3ª prioridade: nomes alternativos (busca case-insensitive via JSON_SEARCH)
        foreach ($altNames as $alt) {
            $normalized = NameHelper::normalize($alt);
            if (! $normalized) {
                continue;
            }

            $record = Content::whereRaw(
                "JSON_SEARCH(LOWER(alternative_names), 'one', ?) IS NOT NULL",
                [$normalized]
            )->first();

            if ($record) {
                return $record;
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fetchers — retornam dados de uma única página
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Busca uma única página da Jikan.
     * Retorna ['data' => items[], 'has_next' => bool].
     */
    private function fetchJikanPage(string $endpoint, callable $log, int $page, int $perPage): array
    {
        try {
            $response = Http::retry(3, 1000)
                ->get(self::JIKAN_BASE . $endpoint, ['page' => $page, 'limit' => $perPage]);

            if (! $response->successful()) {
                $log("[AVISO] Jikan página {$page}: HTTP " . $response->status());

                return ['data' => [], 'has_next' => false];
            }

            return [
                'data'     => $response->json('data', []),
                'has_next' => (bool) $response->json('pagination.has_next_page', false),
            ];
        } catch (\Exception $e) {
            $log("[AVISO] Jikan página {$page}: " . $e->getMessage());
            Log::warning("Jikan {$endpoint} page {$page}", ['error' => $e->getMessage()]);

            return ['data' => [], 'has_next' => false];
        }
    }

    /**
     * Busca uma única página do TMDb /discover.
     * Retorna ['data' => results[], 'total_pages' => int].
     */
    private function fetchTmdbPage(string $endpoint, string $apiKey, callable $log, int $page): array
    {
        try {
            $response = Http::retry(3, 500)
                ->get(self::TMDB_BASE . $endpoint, [
                    'api_key' => $apiKey,
                    'sort_by' => 'popularity.desc',
                    'page'    => $page,
                ]);

            if (! $response->successful()) {
                $log("[AVISO] TMDb página {$page}: HTTP " . $response->status());

                return ['data' => [], 'total_pages' => 0];
            }

            return [
                'data'        => $response->json('results', []),
                'total_pages' => (int) $response->json('total_pages', 1),
            ];
        } catch (\Exception $e) {
            $log("[AVISO] TMDb página {$page}: " . $e->getMessage());
            Log::warning("TMDb {$endpoint} page {$page}", ['error' => $e->getMessage()]);

            return ['data' => [], 'total_pages' => 0];
        }
    }

    /**
     * Detalhes de um item TMDb com append_to_response=videos.
     * Cache de 30min para não repetir requests na mesma sessão de import.
     */
    private function fetchTmdbDetails(string $mediaType, int $id, string $apiKey): array
    {
        return Cache::remember(
            "tmdb.detail.{$mediaType}.{$id}",
            now()->addMinutes(30),
            function () use ($mediaType, $id, $apiKey) {
                $response = Http::retry(3, 500)
                    ->get(self::TMDB_BASE . "/{$mediaType}/{$id}", [
                        'api_key'            => $apiKey,
                        'append_to_response' => 'videos',
                    ]);

                return $response->successful() ? $response->json() : [];
            }
        );
    }

    /**
     * Mapa id→nome de gêneros da TMDb, com cache de 24h.
     */
    private function getTmdbGenreMap(string $apiKey, string $mediaType): array
    {
        return Cache::remember("tmdb.genres.{$mediaType}", now()->addHours(24), function () use ($apiKey, $mediaType) {
            $response = Http::retry(3, 500)
                ->get(self::TMDB_BASE . "/genre/{$mediaType}/list", ['api_key' => $apiKey]);

            return $response->successful()
                ? collect($response->json('genres', []))->pluck('name', 'id')->all()
                : [];
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utilitários de extração e mapeamento
    // ─────────────────────────────────────────────────────────────────────────

    private function extractAltNames(array $item, string $mainName = ''): array
    {
        $candidates = array_filter([
            isset($item['title_english'])  ? trim($item['title_english'])  : null,
            isset($item['title_japanese']) ? trim($item['title_japanese']) : null,
        ]);

        if ($mainName) {
            $normalizedMain = NameHelper::normalize($mainName);
            $candidates     = array_filter($candidates, fn ($a) => NameHelper::normalize($a) !== $normalizedMain);
        }

        return NameHelper::normalizeList(array_values($candidates));
    }

    /** Extrai nomes alternativos de itens TMDb (original_title / original_name). */
    private function extractTmdbAltNames(array $item, string $mainName): array
    {
        $normalizedMain = NameHelper::normalize($mainName);

        $candidates = array_filter([
            isset($item['original_title']) ? trim($item['original_title']) : null,
            isset($item['original_name'])  ? trim($item['original_name'])  : null,
        ]);

        $candidates = array_filter($candidates, fn ($a) => NameHelper::normalize($a) !== $normalizedMain);

        return NameHelper::normalizeList(array_values($candidates));
    }

    /**
     * Detecta a origem do conteúdo (manga / manhwa / manhua) em 4 níveis:
     *  1. Campo type da API (Manhwa / Manhua) — mais confiável
     *  2. Caracteres Unicode no título japonês (Hangul → manhwa, CJK → manhua)
     *  3. Publishers coreanos nas serializações
     *  4. Fallback → manga
     */
    private function detectOriginType(array $item): string
    {
        // Regra 1 — tipo explícito da API
        $apiType = $item['type'] ?? '';
        if ($apiType === 'Manhwa') {
            return 'manhwa';
        }
        if ($apiType === 'Manhua') {
            return 'manhua';
        }

        // Regra 2 — caracteres Unicode no título japonês
        $titleJp = $item['title_japanese'] ?? '';
        if ($titleJp) {
            // Hangul (U+AC00–U+D7AF) → coreano
            if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $titleJp)) {
                return 'manhwa';
            }
            // CJK Unified Ideographs (U+4E00–U+9FFF) → chinês
            if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $titleJp)) {
                return 'manhua';
            }
        }

        // Regra 3 — publishers coreanos nas serializações
        $koreanPublishers = config('content.origin_detection.korean_publishers', []);
        foreach ($item['serializations'] ?? [] as $serial) {
            if (in_array($serial['name'] ?? '', $koreanPublishers, true)) {
                return 'manhwa';
            }
        }

        // Regra 4 — fallback
        return 'manga';
    }

    private function extractJikanGenres(array $item): array
    {
        return collect($item['genres'] ?? [])
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    private function mapTmdbGenres(array $genreIds, array $genreMap): array
    {
        return collect($genreIds)
            ->map(fn ($id) => $genreMap[$id] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function extractTmdbTrailer(array $detail): ?string
    {
        foreach ($detail['videos']['results'] ?? [] as $video) {
            if (($video['site'] ?? '') === 'YouTube' && ($video['type'] ?? '') === 'Trailer') {
                return 'https://www.youtube.com/watch?v=' . $video['key'];
            }
        }

        return null;
    }

    private function tmdbImage(?string $path): ?string
    {
        return $path ? self::TMDB_IMAGE_BASE . $path : null;
    }

    private function tmdbBackdrop(?string $path): ?string
    {
        return $path ? self::TMDB_BACK_BASE . $path : null;
    }

    /**
     * score = rating × log10(votes + 1)
     * Combina qualidade e volume de votos — funciona igual entre Jikan e TMDb.
     */
    private function calculateScore(?float $rating, ?int $votesCount): ?float
    {
        if (! $rating || ! $votesCount || $votesCount <= 0) {
            return null;
        }

        return round($rating * log10($votesCount + 1), 4);
    }

    private function mapJikanStatus(string $status): string
    {
        return match ($status) {
            'Currently Airing', 'Publishing', 'Not yet aired' => 'ongoing',
            'Finished Airing', 'Finished'                      => 'completed',
            'On Hiatus'                                         => 'hiatus',
            'Discontinued'                                      => 'cancelled',
            default                                             => 'ongoing',
        };
    }

    private function mapTmdbTvStatus(string $status): string
    {
        return match ($status) {
            'Returning Series', 'In Production', 'Planned', 'Pilot' => 'ongoing',
            'Ended'                                                   => 'completed',
            'Canceled', 'Cancelled'                                   => 'cancelled',
            default                                                   => 'ongoing',
        };
    }

    /** Heurística para séries sem --details: estreou há +5 anos → completed. */
    private function inferTvStatus(?string $firstAirDate): string
    {
        if (! $firstAirDate || strlen($firstAirDate) < 4) {
            return 'ongoing';
        }

        $year = (int) substr($firstAirDate, 0, 4);

        return ($year > 0 && $year < (now()->year - 5)) ? 'completed' : 'ongoing';
    }

    private function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        try {
            $carbon = Carbon::parse($date);

            return $carbon->year >= 1970 ? $carbon->toDateTimeString() : null;
        } catch (\Exception) {
            return null;
        }
    }

    private function extractYearFromDate(?string $date): ?int
    {
        if (! $date || strlen($date) < 4) {
            return null;
        }

        return $this->cleanYear((int) substr($date, 0, 4));
    }

    /** Parse de duração Jikan: "24 min per ep" / "1 hr 44 min" → minutos. */
    private function parseJikanDuration(?string $duration): ?int
    {
        if (! $duration || stripos($duration, 'unknown') !== false) {
            return null;
        }

        $minutes = 0;

        if (preg_match('/(\d+)\s*hr/i', $duration, $match)) {
            $minutes += (int) $match[1] * 60;
        }

        if (preg_match('/(\d+)\s*min/i', $duration, $match)) {
            $minutes += (int) $match[1];
        }

        return $minutes > 0 ? $minutes : null;
    }

    private function cleanYear(mixed $value): ?int
    {
        $y = (int) $value;

        return ($y >= 1900 && $y <= 2100) ? $y : null;
    }
}
