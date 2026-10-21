<?php

namespace App\Services;

use App\Helpers\LogHelper;
use App\Helpers\NameHelper;
use App\Models\Content;
use App\Models\UserContent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Leva para a biblioteca as obras do histórico de leitura de um site (`reading`,
 * enviado pelo bookmarklet). No ToonLivre vem de /api/auth/me + by-ids; no
 * TiaManhwa, do histórico e dos favoritos da conta (HTML do Madara).
 *
 * Para cada obra, em ordem:
 *  0. já está na biblioteca (vinculada por ID) → só avança o progresso;
 *  A. está no catálogo (`contents`) → adiciona à biblioteca (síncrono, só banco);
 *  B. não está no catálogo → importa da AniList por título ou, se a AniList não
 *     tiver a obra, cria com os dados do próprio site. Faz chamadas HTTP, por
 *     isso roda depois da resposta (ver ChapterCheckController).
 *
 * Em A e B os títulos do site entram em `alternative_names`, o que também ajuda
 * o ChapterMatchService nos próximos syncs.
 *
 * `$site` nos métodos é o site resolvido pelo controller:
 * `{key: 'toonlivre'|'tiamanhwa', id: ?int, adult: bool}` (ver config/content.php).
 */
class ToonLivreLibraryService
{
    /** Diferença máxima de ano (ToonLivre às vezes usa o ano do web novel). */
    private const YEAR_TOLERANCE = 2;

    /** Similaridade mínima para aceitar um resultado da AniList sem título exato. */
    private const ANILIST_FUZZY_MIN = 0.9;

    /**
     * Similaridade mínima para o fallback "único resultado do mesmo ano" (basta
     * compartilhar palavras: a busca da AniList já filtrou por relevância).
     */
    private const ANILIST_SAME_YEAR_MIN = 0.25;

    /** Pausa entre buscas na AniList (limite de 30-90 req/min). */
    private const ANILIST_PAUSE_US = 700_000;

    /** Máximo de títulos buscados na AniList por obra (cada busca custa uma pausa). */
    private const ANILIST_MAX_TERMS = 4;

    private const LIST_STATUSES = ['reading', 'completed', 'paused', 'dropped', 'plan_to_read'];

    private const CONTENT_STATUSES = ['ongoing', 'completed', 'hiatus', 'cancelled'];

    public function __construct(
        private ChapterMatchService $matcher,
        private AniListClient $aniList,
        private AniListContentService $aniListContent,
    ) {}

    /**
     * Converte o `reading` do payload. Tolerante ao formato: itens sem ID, sem
     * título ou de novel são ignorados; mantém a primeira ocorrência de cada ID.
     *
     * @return array<int, array{id: string, alt: ?string, title: ?string, alts: array<int, string>, year: ?int, origin: string,
     *   status: ?string, cover: ?string, last_read: ?string, available: ?string, read_at: ?int, list_status: ?string}>
     */
    public function parseReading(array $input): array
    {
        $works = [];

        foreach ($input as $r) {
            if (! is_array($r)) {
                continue;
            }

            $id = $this->str($r['id'] ?? null);
            $alt = $this->str($r['alternativeTitle'] ?? null);
            $title = $this->str($r['title'] ?? null);
            if ($id === null || isset($works[$id]) || ($alt === null && $title === null)) {
                continue;
            }

            $type = mb_strtolower((string) $this->str($r['type'] ?? null));
            if (str_contains($type, 'novel')) {
                continue;
            }

            $year = $r['releaseYear'] ?? null;
            $readAt = $r['readAt'] ?? null;
            $listStatus = $this->str($r['listStatus'] ?? null);

            $works[$id] = [
                'id' => $id,
                'alt' => $alt,
                'title' => $title,
                'alts' => $this->matcher->stringList($r['alternativeTitles'] ?? null),
                'year' => is_numeric($year) ? (int) $year : null,
                // ToonLivre: Manhwa/Manhua/Webtoon/Manga. TiaManhwa: Pornhwa/Manhwa/
                // Manhua/Mangá/Hentai/Doujinshi.
                'origin' => match (true) {
                    str_contains($type, 'manhua') => 'manhua',
                    (bool) preg_match('/mang|hentai|doujin/', $type) => 'manga',
                    default => 'manhwa',
                },
                'status' => $this->str($r['status'] ?? null),
                'cover' => $this->url($r['cover'] ?? null),
                'last_read' => $this->str($r['lastRead'] ?? null),
                'available' => $this->str($r['available'] ?? null),
                'read_at' => is_numeric($readAt) ? (int) $readAt : null,
                'list_status' => in_array($listStatus, self::LIST_STATUSES, true) ? $listStatus : null,
            ];
        }

        return array_values($works);
    }

    /**
     * Avança `current_units` até o último capítulo lido no site (nunca regride)
     * e tira de "quero ler" o que já está sendo lido. Obra só favoritada (sem
     * capítulo lido) não muda de status. Não salva.
     *
     * @return bool  se o progresso mudou
     */
    public function applyProgress(UserContent $uc, array $work): bool
    {
        $changed = false;
        $read = $this->chapterInt($work['last_read']);

        if ($read !== null && $read > (int) $uc->current_units) {
            $uc->current_units = $read;
            $uc->last_unit_update = $this->readAt($work);
            $changed = true;
        }

        if ($uc->status === 'plan_to_read' && $work['last_read'] !== null) {
            $uc->status = 'reading';
            $changed = true;
        }

        return $changed;
    }

    /**
     * Caso A: procura a obra no catálogo por qualquer título do site (EN, PT e
     * outros nomes) exato, respeitando a origem (manhwa/manhua/manga) e o ano.
     * Vários candidatos: o de ano mais próximo e, empatando, o mais popular.
     */
    public function findInCatalog(array $work): ?Content
    {
        $keys = $this->workKeys($work);
        $words = $this->searchWords($work);
        if (! $keys || ! $words) {
            return null;
        }

        $candidates = Content::query()
            ->where('type', 'manga')
            ->where(function ($q) use ($words) {
                foreach ($words as $w) {
                    $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.$w.'%'])
                        ->orWhereRaw('LOWER(CAST(alternative_names AS CHAR)) LIKE ?', ['%'.$w.'%']);
                }
            })
            ->limit(300)
            ->get();

        $best = null;
        $bestRank = null;

        foreach ($candidates as $c) {
            if ($c->format === 'novel' || ($c->origin_type && $c->origin_type !== $work['origin'])) {
                continue;
            }
            if (! $this->yearOk($c->release_year, $work['year'], self::YEAR_TOLERANCE)) {
                continue;
            }
            if (! array_intersect($keys, $this->contentKeys($c))) {
                continue;
            }

            $rank = [
                $c->release_year && $work['year'] ? abs($c->release_year - $work['year']) : self::YEAR_TOLERANCE + 1,
                -((int) $c->popularity),
            ];
            if ($bestRank === null || $rank < $bestRank) {
                $best = $c;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * Adiciona (ou vincula, se já existir) a obra à biblioteca com o progresso
     * e os dados de fonte do site. Item já vinculado a outro site só recebe o
     * progresso: a fonte dele não muda.
     */
    public function addToLibrary(int $userId, Content $content, array $work, ?int $siteId): UserContent
    {
        $this->mergeNames($content, $work);

        $uc = UserContent::firstOrNew(['user_id' => $userId, 'content_id' => $content->id]);

        if (! $uc->exists) {
            $uc->status = $work['list_status'] ?? 'reading';
            $uc->current_units = $this->chapterInt($work['last_read']) ?? 0;
            $uc->last_unit_update = $this->readAt($work);
        } else {
            $this->applyProgress($uc, $work);

            if ($uc->site_id !== null && (int) $uc->site_id !== (int) $siteId) {
                $uc->save();

                return $uc;
            }
        }

        if ($siteId) {
            $uc->site_id = $siteId;
        }
        $uc->site_title = $work['alt'] ?? $work['title'];
        $uc->site_work_id = $work['id'];
        if ($work['available'] !== null) {
            $uc->site_last_chapter = $work['available'];
        }
        $uc->save();

        return $uc;
    }

    /**
     * Caso B, para obras que não estão no catálogo: AniList e, sem resultado,
     * dados do site. Falha de rede/rate limit na AniList não cria nada — a obra
     * fica para o próximo sync (evita criar duplicata de algo que existe na
     * AniList).
     *
     * @return array<int, array{title: string, via: string, content_id?: int, error?: string}>
     */
    public function importMissing(int $userId, array $works, array $site): array
    {
        $results = [];

        foreach ($works as $i => $work) {
            $label = (string) ($work['alt'] ?? $work['title']);

            try {
                if ($i > 0) {
                    usleep(self::ANILIST_PAUSE_US);
                }

                // Outro sync pode ter criado nesse meio-tempo.
                $content = $this->findInCatalog($work);
                $via = 'catalog';

                if (! $content) {
                    $content = $this->importFromAniList($work);
                    $via = 'anilist';
                }
                if (! $content) {
                    $content = $this->createFromSite($work, $site);
                    $via = $site['key'];
                }

                $this->addToLibrary($userId, $content, $work, $site['id']);
                $results[] = ['title' => $label, 'via' => $via, 'content_id' => $content->id];
            } catch (\Throwable $e) {
                Log::warning('Import do histórico falhou', ['site' => $site['key'], 'work' => $work['id'], 'error' => $e->getMessage()]);
                $results[] = ['title' => $label, 'via' => 'failed', 'error' => $e->getMessage()];
            }
        }

        LogHelper::info('Importação do histórico concluída', [
            'site' => $site['key'],
            'user_id' => $userId,
            'total' => count($results),
            'via' => array_count_values(array_column($results, 'via')),
        ]);

        return $results;
    }

    /**
     * Busca na AniList pelo título EN, pelos outros nomes do site e por último
     * pelo PT. Aceita título exato (em qualquer nome da AniList) com ano
     * próximo, ou título muito parecido com ano quase igual. Reaproveita o
     * registro se o anilist_id já existir.
     *
     * @throws \RuntimeException  erro de transporte/GraphQL da AniList
     */
    public function importFromAniList(array $work): ?Content
    {
        $country = match ($work['origin']) {
            'manhua' => 'CN',
            'manga' => 'JP',
            default => 'KR',
        };

        $terms = array_slice(
            array_values(array_unique(array_filter(array_merge([$work['alt']], $work['alts'], [$work['title']])))),
            0,
            self::ANILIST_MAX_TERMS
        );

        foreach ($terms as $n => $term) {
            if ($n > 0) {
                usleep(self::ANILIST_PAUSE_US);
            }

            $media = $this->pickAniListMedia($work, $this->aniList->searchManga($term, $country));
            if (! $media) {
                continue;
            }

            $existing = Content::where('anilist_id', $media['id'])->first()
                ?? (! empty($media['idMal'])
                    ? Content::where('mal_id', $media['idMal'])->where('type', 'manga')->first()
                    : null);
            if ($existing) {
                return $existing;
            }

            $data = $this->aniListContent->normalizeAniListItem($media, 'manga');
            $data['alternative_names'] = NameHelper::normalizeList(array_merge(
                $data['alternative_names'] ?? [],
                $this->workNames($work)
            ));
            $data['cover'] ??= $work['cover'];
            $data['release_year'] ??= $work['year'];
            if (! in_array($data['status'] ?? null, self::CONTENT_STATUSES, true)) {
                $data['status'] = $this->contentStatus($work['status']);
            }

            $content = Content::create($data);
            LogHelper::info('Obra importada da AniList via histórico do site', ['content_id' => $content->id, 'anilist_id' => $media['id']]);

            return $content;
        }

        return null;
    }

    /** Obra que não existe na AniList: cadastro mínimo com os dados do site. */
    public function createFromSite(array $work, array $site): Content
    {
        $existing = Content::where('source', $site['key'])->where('external_id', $work['id'])->first();
        if ($existing) {
            return $existing;
        }

        $country = match ($work['origin']) {
            'manhua' => 'CN',
            'manga' => 'JP',
            default => 'KR',
        };

        $content = Content::create([
            'source' => $site['key'],
            'external_id' => $work['id'],
            'name' => $work['alt'] ?? $work['title'],
            'alternative_names' => NameHelper::normalizeList($this->workNames($work)),
            'type' => 'manga',
            'origin_type' => $work['origin'],
            'status' => $this->contentStatus($work['status']),
            'is_adult' => (bool) $site['adult'],
            'cover' => $work['cover'],
            'release_year' => $work['year'],
            'country' => $country,
            'original_language' => match ($country) {
                'CN' => 'zh',
                'JP' => 'ja',
                default => 'ko',
            },
        ]);
        LogHelper::info('Obra criada com dados do site', ['site' => $site['key'], 'content_id' => $content->id, 'work_id' => $work['id']]);

        return $content;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function pickAniListMedia(array $work, array $results): ?array
    {
        $keys = $this->workKeys($work);
        $names = $this->workNames($work);
        $best = null;
        $bestRank = null;
        $sameYear = [];

        foreach ($results as $m) {
            if (($m['format'] ?? null) === 'NOVEL' || empty($m['id'])) {
                continue;
            }

            $year = $m['startDate']['year'] ?? null;
            $mNames = array_filter(array_merge(
                [$m['title']['english'] ?? null, $m['title']['romaji'] ?? null, $m['title']['native'] ?? null],
                $m['synonyms'] ?? []
            ));

            $mKeys = [];
            foreach ($mNames as $n) {
                $mKeys = array_merge($mKeys, $this->matcher->titleKeys((string) $n));
            }

            if (array_intersect($keys, $mKeys)) {
                if (! $this->yearOk($year, $work['year'], self::YEAR_TOLERANCE)) {
                    continue;
                }
                $score = 1.0;
            } else {
                // Sem título exato só aceita ano conhecido e quase igual.
                if (! $year || ! $work['year'] || abs($year - $work['year']) > 1) {
                    continue;
                }
                $score = 0.0;
                foreach ($names as $a) {
                    foreach ($mNames as $b) {
                        $score = max($score, $this->matcher->titleSimilarity($a, (string) $b));
                    }
                }
                if ($year === $work['year'] && $score >= self::ANILIST_SAME_YEAR_MIN) {
                    $sameYear[] = $m;
                }
                if ($score < self::ANILIST_FUZZY_MIN) {
                    continue;
                }
            }

            $rank = [-$score, $year && $work['year'] ? abs($year - $work['year']) : self::YEAR_TOLERANCE + 1];
            if ($bestRank === null || $rank < $bestRank) {
                $best = $m;
                $bestRank = $rank;
            }
        }

        // Tradução diferente do mesmo título ("...Mercenary's Machinations" ×
        // "...Mercenary Has a Plan"): aceita se for o único resultado do mesmo ano.
        return $best ?? (count($sameYear) === 1 ? $sameYear[0] : null);
    }

    /** Adiciona os títulos do site aos nomes alternativos da obra. */
    private function mergeNames(Content $content, array $work): void
    {
        $current = (array) ($content->alternative_names ?? []);
        $merged = NameHelper::normalizeList(array_merge($current, $this->workNames($work)));

        if ($merged !== array_values($current)) {
            $content->alternative_names = $merged;
            $content->save();
        }
    }

    /**
     * Todos os títulos da obra no site: EN, PT e outros nomes.
     *
     * @return array<int, string>
     */
    private function workNames(array $work): array
    {
        return array_values(array_unique(array_filter(array_merge([$work['alt'], $work['title']], $work['alts']))));
    }

    /** @return array<int, string> */
    private function workKeys(array $work): array
    {
        $keys = [];
        foreach ($this->workNames($work) as $t) {
            $keys = array_merge($keys, $this->matcher->titleKeys($t));
        }

        return array_values(array_unique($keys));
    }

    /** @return array<int, string> */
    private function contentKeys(Content $c): array
    {
        $keys = $this->matcher->titleKeys((string) $c->name);
        foreach ((array) ($c->alternative_names ?? []) as $n) {
            if (is_scalar($n)) {
                $keys = array_merge($keys, $this->matcher->titleKeys((string) $n));
            }
        }

        return $keys;
    }

    /**
     * Palavra mais longa de cada título, para pré-filtrar o catálogo no banco
     * (a comparação exata é feita depois, em PHP).
     *
     * @return array<int, string>
     */
    private function searchWords(array $work): array
    {
        $words = [];
        foreach ($this->workNames($work) as $t) {
            $tokens = explode(' ', $this->matcher->normalize($t));
            usort($tokens, fn ($a, $b) => strlen($b) <=> strlen($a));
            if (strlen($tokens[0] ?? '') >= 3) {
                $words[] = $tokens[0];
            }
        }

        return array_slice(array_values(array_unique($words)), 0, 8);
    }

    private function yearOk(?int $a, ?int $b, int $tolerance): bool
    {
        return ! $a || ! $b || abs($a - $b) <= $tolerance;
    }

    /** ToonLivre: Ongoing/Completed/Hiatus/Canceled. TiaManhwa: Em lançamento/Completo/Hiato/Cancelado. */
    private function contentStatus(?string $siteStatus): string
    {
        $s = mb_strtolower((string) $siteStatus);

        return match (true) {
            str_contains($s, 'complet') || str_contains($s, 'finish') || str_contains($s, 'finaliz') || str_contains($s, 'conclu') => 'completed',
            str_contains($s, 'hiatus') || str_contains($s, 'hiato') => 'hiatus',
            str_contains($s, 'cancel') || str_contains($s, 'drop') => 'cancelled',
            default => 'ongoing',
        };
    }

    private function chapterInt(?string $chapter): ?int
    {
        if ($chapter === null) {
            return null;
        }
        $n = str_replace(',', '.', $chapter);

        return is_numeric($n) ? (int) floor((float) $n) : null;
    }

    private function readAt(array $work): Carbon
    {
        return $work['read_at'] ? Carbon::createFromTimestampMs($work['read_at']) : now();
    }

    private function str(mixed $v): ?string
    {
        if (! is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private function url(mixed $v): ?string
    {
        $s = $this->str($v);

        return $s !== null && str_starts_with($s, 'https://') && strlen($s) <= 255 ? $s : null;
    }
}
