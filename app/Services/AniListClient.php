<?php

namespace App\Services;

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
        $response = Http::timeout(15)
            ->retry(3, 500)
            ->acceptJson()
            ->asJson()
            ->post(self::ENDPOINT, [
                'query' => $graphql,
                'variables' => $variables,
            ]);

        // Tratamento explícito de 429 (rate limit): respeita Retry-After e tenta 1x mais.
        if ($response->status() === 429) {
            $wait = (int) ($response->header('Retry-After') ?: 60);
            Log::warning('AniList 429 rate limit', ['retry_after' => $wait]);
            sleep($wait > 0 ? $wait : 60);

            $response = Http::timeout(15)
                ->retry(3, 500)
                ->acceptJson()
                ->asJson()
                ->post(self::ENDPOINT, [
                    'query' => $graphql,
                    'variables' => $variables,
                ]);
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
    public function fetchPage(string $type, int $page = 1, bool $includeAdult = false): array
    {
        $adultFilter = $includeAdult ? ', isAdult: true' : ', isAdult: false';

        $fields = self::MEDIA_FIELDS;
        $query = <<<GQL
        query (\$page: Int, \$type: MediaType) {
            Page(page: \$page, perPage: 50) {
                media(type: \$type, sort: POPULARITY_DESC{$adultFilter}) {
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
