<?php

namespace App\Services;

use App\Models\Content;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Importação de filmes e séries via TMDb.
 *
 * NOTA DA MIGRAÇÃO ANILIST:
 *  - O caminho de anime/mangá (Jikan) foi REMOVIDO deste serviço. A fonte primária
 *    de anime/mangá agora é a AniList (ver App\Services\AniListContentService).
 *  - Removidos: importAnime(), importManga(), detectOriginType() e toda a lógica de
 *    detecção de origem por Unicode/publishers, além dos helpers exclusivos do Jikan.
 *  - O caminho TMDb (movie/tv) permanece intacto, exceto pelas colunas renomeadas na
 *    auditoria: `background` → `banner_image` e `last_unit_update` → `release_date`.
 */
class ExternalContentService
{
    private const TMDB_BASE = 'https://api.themoviedb.org/3';

    private const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p/w500';

    private const TMDB_BACK_BASE = 'https://image.tmdb.org/t/p/original';

    // Campos que --force nunca sobrescreve (identidade do registro)
    private const FORCE_SKIP = ['name', 'alternative_names', 'type', 'source', 'external_id'];

    public function importMovies(
        callable $log,
        int $pageStart = 1,
        int $pageEnd = 5,
        bool $force = false,
        bool $withDetails = false,
        bool $includeAdult = false
    ): int {
        $apiKey = config('services.tmdb.key');

        if (! $apiKey) {
            $log('[ERRO] TMDB_API_KEY não configurada. Adicione ao .env e execute novamente.');

            return 0;
        }

        $genreMap = $this->getTmdbGenreMap($apiKey, 'movie');
        $imported = 0;

        for ($page = $pageStart; $page <= $pageEnd; $page++) {
            $result = $this->fetchTmdbPage('/discover/movie', $apiKey, $log, $page, $includeAdult);

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

                    $rating = isset($item['vote_average']) ? (float) $item['vote_average'] : null;
                    $votesCount = $item['vote_count'] ?? null;

                    $data = [
                        'external_id' => (string) $item['id'],
                        'source' => 'tmdb',
                        'name' => $name,
                        'alternative_names' => $this->extractTmdbAltNames($item, $name),
                        'cover' => $this->tmdbImage($item['poster_path'] ?? null),
                        'banner_image' => $this->tmdbBackdrop($item['backdrop_path'] ?? null),
                        'type' => 'movie',
                        'status' => 'completed',
                        'is_adult' => (bool) ($item['adult'] ?? false),
                        'age_rating' => $detail ? $this->extractTmdbAgeRating($detail) : null,
                        'total_units' => null,
                        'total_seasons' => null,
                        'duration' => $detail ? ($detail['runtime'] ?? null) : null,
                        'release_date' => $this->parseDate($item['release_date'] ?? null),
                        'trailer_url' => $detail ? $this->extractTmdbTrailer($detail) : null,
                        'rating' => $rating,
                        'popularity' => isset($item['popularity']) ? (int) $item['popularity'] : null,
                        'votes_count' => $votesCount,
                        'score' => $this->calculateScore($rating, $votesCount),
                        'synopsis' => $item['overview'] ?? null,
                        'genres' => $this->mapTmdbGenres($item['genre_ids'] ?? [], $genreMap),
                        'studios' => $detail ? $this->extractTmdbStudios($detail) : null,
                        'tagline' => $detail ? ($detail['tagline'] ?: null) : null,
                        'demographics' => null,
                        'themes' => null,
                        'networks' => null,
                        'release_year' => $this->extractYearFromDate($item['release_date'] ?? null),
                        'original_language' => $item['original_language'] ?? null,
                        'country' => $detail
                            ? ($detail['production_countries'][0]['iso_3166_1'] ?? null)
                            : null,
                    ];

                    $imported += $this->upsert($data, [], $force, $log, 'movie');
                } catch (\Exception $e) {
                    $log("[ERRO ITEM][movie][pág {$page}] {$name}: ".$e->getMessage());
                    Log::warning('ImportContents item error', [
                        'type' => 'movie',
                        'page' => $page,
                        'name' => $name,
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
        int $pageStart = 1,
        int $pageEnd = 5,
        bool $force = false,
        bool $withDetails = false,
        bool $includeAdult = false
    ): int {
        $apiKey = config('services.tmdb.key');

        if (! $apiKey) {
            $log('[ERRO] TMDB_API_KEY não configurada. Adicione ao .env e execute novamente.');

            return 0;
        }

        $genreMap = $this->getTmdbGenreMap($apiKey, 'tv');
        $imported = 0;

        for ($page = $pageStart; $page <= $pageEnd; $page++) {
            $result = $this->fetchTmdbPage('/discover/tv', $apiKey, $log, $page, $includeAdult);

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

                    $rating = isset($item['vote_average']) ? (float) $item['vote_average'] : null;
                    $votesCount = $item['vote_count'] ?? null;

                    $status = $detail
                        ? $this->mapTmdbTvStatus($detail['status'] ?? '')
                        : $this->inferTvStatus($item['first_air_date'] ?? null);

                    $data = [
                        'external_id' => (string) $item['id'],
                        'source' => 'tmdb',
                        'name' => $name,
                        'alternative_names' => $this->extractTmdbAltNames($item, $name),
                        'cover' => $this->tmdbImage($item['poster_path'] ?? null),
                        'banner_image' => $this->tmdbBackdrop($item['backdrop_path'] ?? null),
                        'type' => 'tv',
                        'status' => $status,
                        'is_adult' => (bool) ($item['adult'] ?? false),
                        'age_rating' => $detail ? $this->extractTmdbAgeRating($detail) : null,
                        'total_units' => $detail ? ($detail['number_of_episodes'] ?? null) : null,
                        'total_seasons' => $detail ? ($detail['number_of_seasons'] ?? null) : null,
                        'duration' => $detail ? ($detail['episode_run_time'][0] ?? null) : null,
                        'release_date' => $this->parseDate($item['first_air_date'] ?? null),
                        'trailer_url' => $detail ? $this->extractTmdbTrailer($detail) : null,
                        'rating' => $rating,
                        'popularity' => isset($item['popularity']) ? (int) $item['popularity'] : null,
                        'votes_count' => $votesCount,
                        'score' => $this->calculateScore($rating, $votesCount),
                        'synopsis' => $item['overview'] ?? null,
                        'genres' => $this->mapTmdbGenres($item['genre_ids'] ?? [], $genreMap),
                        'studios' => $detail ? $this->extractTmdbStudios($detail) : null,
                        'tagline' => $detail ? ($detail['tagline'] ?: null) : null,
                        'networks' => $detail ? $this->extractTmdbNetworks($detail) : null,
                        'season_episodes' => $detail ? $this->extractSeasonEpisodes($detail) : null,
                        'demographics' => null,
                        'themes' => null,
                        'release_year' => $this->extractYearFromDate($item['first_air_date'] ?? null),
                        'original_language' => $item['original_language'] ?? null,
                        'country' => $item['origin_country'][0]
                            ?? ($detail['origin_country'][0] ?? null),
                    ];

                    $imported += $this->upsert($data, [], $force, $log, 'tv');
                } catch (\Exception $e) {
                    $log("[ERRO ITEM][tv][pág {$page}] {$name}: ".$e->getMessage());
                    Log::warning('ImportContents item error', [
                        'type' => 'tv',
                        'page' => $page,
                        'name' => $name,
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
    // Lógica de insert / update (upsert) — compartilhada
    // ─────────────────────────────────────────────────────────────────────────

    private function upsert(array $data, array $altNames, bool $force, callable $log, string $type): int
    {
        $name = $data['name'];
        $source = $data['source'] ?? null;
        $extId = $data['external_id'] ?? null;
        $label = $data['origin_type'] ?? $type;
        $existing = $this->findExisting($name, $altNames, $source, $extId, $type);

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

    private function findExisting(
        string $name,
        array $altNames,
        ?string $source,
        ?string $externalId,
        string $type
    ): ?Content {
        // 1ª prioridade: source + external_id + type.
        // O `type` é essencial aqui: na TMDb os IDs de movie e tv são namespaces
        // INDEPENDENTES (movie #1399 ≠ tv #1399). Sem o filtro de type, importar a
        // série casaria com o filme de mesmo ID e um sobrescreveria o outro.
        if ($source && $externalId) {
            $record = Content::where('source', $source)
                ->where('external_id', $externalId)
                ->where('type', $type)
                ->first();

            if ($record) {
                return $record;
            }
        }

        // 2ª prioridade: nome normalizado, escopo por type
        $record = Content::whereRaw('LOWER(TRIM(name)) = ?', [\App\Helpers\NameHelper::normalize($name)])
            ->where('type', $type)
            ->first();
        if ($record) {
            return $record;
        }

        // 3ª prioridade: nomes alternativos, escopo por type
        foreach ($altNames as $alt) {
            $normalized = \App\Helpers\NameHelper::normalize($alt);
            if (! $normalized) {
                continue;
            }

            $record = Content::whereRaw(
                "JSON_SEARCH(LOWER(alternative_names), 'one', ?) IS NOT NULL",
                [$normalized]
            )->where('type', $type)->first();

            if ($record) {
                return $record;
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fetchers TMDb
    // ─────────────────────────────────────────────────────────────────────────

    private function fetchTmdbPage(string $endpoint, string $apiKey, callable $log, int $page, bool $includeAdult = false): array
    {
        try {
            $params = [
                'api_key' => $apiKey,
                'sort_by' => 'popularity.desc',
                'page' => $page,
            ];

            // --adult: remove o filtro de conteúdo adulto da TMDb (padrão = false).
            if ($includeAdult) {
                $params['include_adult'] = true;
            }

            $response = Http::retry(3, 500)
                ->get(self::TMDB_BASE.$endpoint, $params);

            if (! $response->successful()) {
                $log("[AVISO] TMDb página {$page}: HTTP ".$response->status());

                return ['data' => [], 'total_pages' => 0];
            }

            return [
                'data' => $response->json('results', []),
                'total_pages' => (int) $response->json('total_pages', 1),
            ];
        } catch (\Exception $e) {
            $log("[AVISO] TMDb página {$page}: ".$e->getMessage());
            Log::warning("TMDb {$endpoint} page {$page}", ['error' => $e->getMessage()]);

            return ['data' => [], 'total_pages' => 0];
        }
    }

    // In-process cache: avoids DB pollution during bulk import runs.
    private array $tmdbDetailCache = [];

    private function fetchTmdbDetails(string $mediaType, int $id, string $apiKey): array
    {
        $key = "{$mediaType}.{$id}";

        if (isset($this->tmdbDetailCache[$key])) {
            return $this->tmdbDetailCache[$key];
        }

        $response = Http::retry(3, 500)
            ->get(self::TMDB_BASE."/{$mediaType}/{$id}", [
                'api_key' => $apiKey,
                'append_to_response' => 'videos,content_ratings,release_dates',
            ]);

        return $this->tmdbDetailCache[$key] = $response->successful() ? $response->json() : [];
    }

    /**
     * Busca um item TMDb (movie/tv) e devolve só os campos críticos usados pelo
     * comando content:sync-updates. Não toca no fluxo de import.
     *
     * @param  string  $type  'movie' ou 'tv'
     * @return array|null  ['cover','banner_image','status','total_units','total_seasons','end_date']
     */
    public function fetchForSync(string $externalId, string $type): ?array
    {
        $apiKey = config('services.tmdb.key');
        if (! $apiKey) {
            return null;
        }

        $response = Http::timeout(15)->retry(2, 500)
            ->get(self::TMDB_BASE."/{$type}/{$externalId}", ['api_key' => $apiKey]);

        if ($response->status() === 429) {
            sleep(60);
            $response = Http::timeout(15)->get(self::TMDB_BASE."/{$type}/{$externalId}", ['api_key' => $apiKey]);
        }

        if (! $response->successful()) {
            return null;
        }

        $d = $response->json();
        $isTv = $type === 'tv';

        return [
            'cover' => $this->tmdbImage($d['poster_path'] ?? null),
            'banner_image' => $this->tmdbBackdrop($d['backdrop_path'] ?? null),
            'status' => $isTv ? $this->mapTmdbTvStatus($d['status'] ?? '') : 'completed',
            'total_units' => $isTv ? ($d['number_of_episodes'] ?? null) : null,
            'total_seasons' => $isTv ? ($d['number_of_seasons'] ?? null) : null,
            'end_date' => $isTv ? ($d['last_air_date'] ?? null) : null,
        ];
    }

    private function getTmdbGenreMap(string $apiKey, string $mediaType): array
    {
        return Cache::remember("tmdb.genres.{$mediaType}", now()->addHours(24), function () use ($apiKey, $mediaType) {
            $response = Http::retry(3, 500)
                ->get(self::TMDB_BASE."/genre/{$mediaType}/list", ['api_key' => $apiKey]);

            return $response->successful()
                ? collect($response->json('genres', []))->pluck('name', 'id')->all()
                : [];
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utilitários TMDb
    // ─────────────────────────────────────────────────────────────────────────

    /** Extrai nomes alternativos de itens TMDb (original_title / original_name). */
    private function extractTmdbAltNames(array $item, string $mainName): array
    {
        $normalizedMain = \App\Helpers\NameHelper::normalize($mainName);

        $candidates = array_filter([
            isset($item['original_title']) ? trim($item['original_title']) : null,
            isset($item['original_name']) ? trim($item['original_name']) : null,
        ]);

        $candidates = array_filter($candidates, fn ($a) => \App\Helpers\NameHelper::normalize($a) !== $normalizedMain);

        return \App\Helpers\NameHelper::normalizeList(array_values($candidates));
    }

    /** Produtoras TMDb: production_companies (+ criadores para TV). */
    private function extractTmdbStudios(array $detail): ?array
    {
        $names = collect($detail['production_companies'] ?? [])
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        $creators = collect($detail['created_by'] ?? [])
            ->pluck('name')
            ->filter()
            ->all();

        $all = array_unique(array_merge($creators, $names));

        return ! empty($all) ? array_values($all) : null;
    }

    /** Redes/plataformas TMDb (TV): networks. */
    private function extractTmdbNetworks(array $detail): ?array
    {
        $names = collect($detail['networks'] ?? [])
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        return ! empty($names) ? $names : null;
    }

    /**
     * Extrai episódios por temporada do detalhe TMDb.
     * Retorna ["1" => 10, "2" => 13, ...] ignorando season 0 (especiais).
     */
    private function extractSeasonEpisodes(array $detail): ?array
    {
        if (empty($detail['seasons'])) {
            return null;
        }

        $result = [];
        foreach ($detail['seasons'] as $season) {
            $num = $season['season_number'] ?? null;
            $count = $season['episode_count'] ?? 0;

            if ($num === null || $num < 1 || $count < 1) {
                continue;
            }

            $result[(string) $num] = (int) $count;
        }

        return ! empty($result) ? $result : null;
    }

    /**
     * Extrai classificação etária do TMDb.
     * Filmes: release_dates.results[country=US].release_dates[].certification
     * TV: content_ratings.results[country=US].rating
     */
    private function extractTmdbAgeRating(array $detail): ?string
    {
        foreach ($detail['content_ratings']['results'] ?? [] as $entry) {
            if (($entry['iso_3166_1'] ?? '') === 'US' && ! empty($entry['rating'])) {
                return $entry['rating'];
            }
        }

        foreach ($detail['release_dates']['results'] ?? [] as $entry) {
            if (($entry['iso_3166_1'] ?? '') === 'US') {
                foreach ($entry['release_dates'] ?? [] as $rd) {
                    if (! empty($rd['certification'])) {
                        return $rd['certification'];
                    }
                }
            }
        }

        return null;
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
                return 'https://www.youtube.com/watch?v='.$video['key'];
            }
        }

        return null;
    }

    private function tmdbImage(?string $path): ?string
    {
        return $path ? self::TMDB_IMAGE_BASE.$path : null;
    }

    private function tmdbBackdrop(?string $path): ?string
    {
        return $path ? self::TMDB_BACK_BASE.$path : null;
    }

    /**
     * score = rating × log10(votes + 1)
     * Combina qualidade e volume de votos.
     */
    private function calculateScore(?float $rating, ?int $votesCount): ?float
    {
        if (! $rating || ! $votesCount || $votesCount <= 0) {
            return null;
        }

        return round($rating * log10($votesCount + 1), 4);
    }

    private function mapTmdbTvStatus(string $status): string
    {
        return match ($status) {
            'Returning Series', 'In Production', 'Planned', 'Pilot' => 'ongoing',
            'Ended' => 'completed',
            'Canceled', 'Cancelled' => 'cancelled',
            default => 'ongoing',
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

    private function cleanYear(mixed $value): ?int
    {
        $y = (int) $value;

        return ($y >= 1900 && $y <= 2100) ? $y : null;
    }
}
