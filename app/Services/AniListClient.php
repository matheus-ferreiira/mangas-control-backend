<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente da AniList GraphQL API (https://graphql.anilist.co).
 * Fonte primária de anime/mangá após a migração do Jikan.
 */
class AniListClient
{
    private const ENDPOINT = 'https://graphql.anilist.co';

    /** Esperas máximas por 429 numa mesma query (cada uma respeita o Retry-After). */
    private const MAX_429_WAITS = 5;

    /**
     * Campos pedidos para cada Media. Reutilizados em Page e em buscas unitárias.
     */
    private const MEDIA_FIELDS = <<<'GQL'
        id
        idMal
        title { romaji english native }
        synonyms
        type
        format
        status
        description
        startDate { year month day }
        endDate { year month day }
        season
        seasonYear
        episodes
        chapters
        volumes
        duration
        countryOfOrigin
        isAdult
        coverImage { extraLarge }
        bannerImage
        genres
        tags { name category isGeneralSpoiler }
        averageScore
        popularity
        favourites
        studios { nodes { name isAnimationStudio } }
        trailer { id site }
        source
    GQL;

    /**
     * Executa uma query GraphQL e retorna o nó `data`.
     *
     * @throws RuntimeException em erro de transporte ou erro GraphQL.
     */
    public function query(string $graphql, array $variables = []): array
    {
        $response = $this->send($graphql, $variables);

        // 429 (rate limit): espera o Retry-After e tenta de novo, até MAX_429_WAITS vezes.
        // O retry() do send não repete 429, senão lançaria exceção antes daqui.
        for ($i = 0; $response->status() === 429 && $i < self::MAX_429_WAITS; $i++) {
            $wait = (int) ($response->header('Retry-After') ?: 60);
            Log::warning('AniList 429 rate limit', ['retry_after' => $wait, 'attempt' => $i + 1]);
            sleep(max(1, $wait) + 1);

            $response = $this->send($graphql, $variables);
        }

        if (! $response->successful()) {
            $msg = $response->json('errors.0.message') ?? ('HTTP '.$response->status());
            throw new RuntimeException("AniList request falhou: {$msg}");
        }

        if ($response->json('errors')) {
            $msg = $response->json('errors.0.message') ?? 'erro GraphQL desconhecido';
            throw new RuntimeException("AniList GraphQL error: {$msg}");
        }

        return $response->json('data', []);
    }

    /** POST com retry para falhas transitórias, exceto 429 (tratado em query()). */
    private function send(string $graphql, array $variables): Response
    {
        return Http::timeout(15)
            ->retry(3, 500, fn ($e) => ! ($e instanceof RequestException && $e->response->status() === 429), throw: false)
            ->acceptJson()
            ->asJson()
            ->post(self::ENDPOINT, [
                'query' => $graphql,
                'variables' => $variables,
            ]);
    }

    /**
     * Uma página (50 itens, ordem por ID) de obras com início entre as datas,
     * exclusivas, no formato FuzzyDateInt (YYYYMMDD; só ano = YYYY0000).
     * Contorna o teto de 5.000 resultados por consulta fatiando por data.
     *
     * @return array{media: array<int, array>, hasNextPage: bool}
     */
    public function fetchDateSlice(string $type, int $page, int $startGreater, int $startLesser, bool $includeAdult = false, ?string $countryOfOrigin = null, ?string $format = null): array
    {
        $adultFilter = $includeAdult ? ', isAdult: true' : ', isAdult: false';
        $countryFilter = $countryOfOrigin ? ', countryOfOrigin: "'.$countryOfOrigin.'"' : '';
        $formatFilter = $format ? ', format: '.$format : '';

        $fields = self::MEDIA_FIELDS;
        $query = <<<GQL
        query (\$page: Int, \$type: MediaType, \$g: FuzzyDateInt, \$l: FuzzyDateInt) {
            Page(page: \$page, perPage: 50) {
                pageInfo { hasNextPage }
                media(type: \$type, sort: ID, startDate_greater: \$g, startDate_lesser: \$l{$adultFilter}{$countryFilter}{$formatFilter}) {
                    {$fields}
                }
            }
        }
        GQL;

        $data = $this->query($query, ['page' => $page, 'type' => $type, 'g' => $startGreater, 'l' => $startLesser]);

        return [
            'media' => $data['Page']['media'] ?? [],
            'hasNextPage' => (bool) ($data['Page']['pageInfo']['hasNextPage'] ?? false),
        ];
    }

    /**
     * Busca uma página (50 itens) ordenada por popularidade desc.
     *
     * @param  string  $type  'ANIME' ou 'MANGA'
     * @param  bool  $includeAdult  Modo adulto dedicado:
     *                              false → isAdult:false (exclui +18; padrão).
     *                              true  → isAdult:true  (traz SOMENTE +18).
     *                              Motivo: com POPULARITY_DESC o +18 nunca aparece na
     *                              página 1 se apenas removêssemos o filtro (os mainstream
     *                              dominam o ranking). isAdult:true garante conteúdo +18.
     * @return array<int, array>  data.Page.media[]
     */
    public function fetchPage(string $type, int $page = 1, bool $includeAdult = false, ?string $countryOfOrigin = null, ?string $format = null): array
    {
        $adultFilter = $includeAdult ? ', isAdult: true' : ', isAdult: false';
        // countryOfOrigin é o scalar CountryCode (string ISO 3166-1 alpha-2): "KR"/"CN"/"JP".
        $countryFilter = $countryOfOrigin ? ', countryOfOrigin: "'.$countryOfOrigin.'"' : '';
        // format é o enum MediaFormat (sem aspas): NOVEL/MANGA/ONE_SHOT.
        $formatFilter = $format ? ', format: '.$format : '';

        $fields = self::MEDIA_FIELDS;
        $query = <<<GQL
        query (\$page: Int, \$type: MediaType) {
            Page(page: \$page, perPage: 50) {
                media(type: \$type, sort: POPULARITY_DESC{$adultFilter}{$countryFilter}{$formatFilter}) {
                    {$fields}
                }
            }
        }
        GQL;

        $data = $this->query($query, ['page' => $page, 'type' => $type]);

        return $data['Page']['media'] ?? [];
    }

    /**
     * Busca um único item pelo ID da AniList.
     */
    public function fetchById(int $anilistId): ?array
    {
        $fields = self::MEDIA_FIELDS;
        $query = <<<GQL
        query (\$id: Int) {
            Media(id: \$id) {
                {$fields}
            }
        }
        GQL;

        $data = $this->query($query, ['id' => $anilistId]);

        return $data['Media'] ?? null;
    }

    /**
     * Busca mangás por título (busca textual da AniList, ordenada por relevância).
     * Não filtra +18: a obra precisa ser encontrada mesmo se for adulta.
     *
     * @param  string|null  $countryOfOrigin  "KR" / "CN" / "JP"
     * @return array<int, array>  data.Page.media[]
     */
    public function searchManga(string $search, ?string $countryOfOrigin = null, int $perPage = 10): array
    {
        $countryFilter = $countryOfOrigin ? ', countryOfOrigin: "'.$countryOfOrigin.'"' : '';

        $fields = self::MEDIA_FIELDS;
        $query = <<<GQL
        query (\$search: String, \$perPage: Int) {
            Page(page: 1, perPage: \$perPage) {
                media(search: \$search, type: MANGA, sort: SEARCH_MATCH{$countryFilter}) {
                    {$fields}
                }
            }
        }
        GQL;

        $data = $this->query($query, ['search' => $search, 'perPage' => $perPage]);

        return $data['Page']['media'] ?? [];
    }

    /**
     * Busca um único item pelo MAL ID — usado no de-para e no fallback Jikan.
     *
     * @param  string  $type  'ANIME' ou 'MANGA'
     */
    public function fetchByMalId(int $malId, string $type): ?array
    {
        $fields = self::MEDIA_FIELDS;
        $query = <<<GQL
        query (\$malId: Int, \$type: MediaType) {
            Media(idMal: \$malId, type: \$type) {
                {$fields}
            }
        }
        GQL;

        $data = $this->query($query, ['malId' => $malId, 'type' => $type]);

        return $data['Media'] ?? null;
    }
}
