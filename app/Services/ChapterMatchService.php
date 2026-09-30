<?php

namespace App\Services;

use App\Models\UserContent;
use Illuminate\Support\Collection;

/**
 * Vincula os lançamentos do ToonLivre (enviados pelo bookmarklet) aos itens da
 * biblioteca. Camadas, em ordem:
 *
 *  1. ID da obra no site já salvo em `user_contents.site_work_id`;
 *  2. título exato — inglês (`alternativeTitle`) antes de português (`title`),
 *     primeiro como está e depois sem artigo inicial ("The Legend of..." ×
 *     "Legend of...");
 *  3. título parecido (>= FUZZY_MIN) com ano de lançamento próximo. O ano separa
 *     continuações: "Solo Leveling" (2018) × "Solo Leveling: Ragnarok" (2024).
 *
 * A camada 3 só roda para quem ainda não tem vínculo e nunca reutiliza um
 * lançamento já casado. Candidatos próximos demais entre si ficam como ambíguos
 * (não vinculam) para o usuário resolver pelo `site_title`.
 */
class ChapterMatchService
{
    /** Similaridade mínima para vincular por título parecido. */
    private const FUZZY_MIN = 0.85;

    /** Vantagem mínima do melhor candidato sobre o 2º; abaixo disso é ambíguo. */
    private const FUZZY_GAP = 0.1;

    /** Diferença máxima de ano (ToonLivre às vezes usa o ano do web novel). */
    private const YEAR_TOLERANCE = 2;

    private const STOPWORDS = [
        'the', 'a', 'an', 'of', 'and', 'in', 'to',
        'o', 'os', 'as', 'de', 'do', 'da', 'dos', 'das', 'e', 'no', 'na',
    ];

    /**
     * Converte o payload do bookmarklet em lançamentos válidos. Tolerante ao
     * formato (itens malformados são ignorados) e compatível com o payload
     * antigo, que só tinha `alternativeTitle` + `chapter`. Mantém a primeira
     * ocorrência de cada obra (a mais recente).
     *
     * @return array<int, array{id: ?string, alt: ?string, title: ?string, year: ?int, chapter: string}>
     */
    public function parseReleases(array $input): array
    {
        $releases = [];
        $seen = [];

        foreach ($input as $r) {
            if (! is_array($r)) {
                continue;
            }

            $chapter = $r['chapter'] ?? null;
            if (! is_scalar($chapter) || (string) $chapter === '') {
                continue;
            }

            $alt = $this->stringOrNull($r['alternativeTitle'] ?? null);
            $title = $this->stringOrNull($r['title'] ?? null);
            if ($alt === null && $title === null) {
                continue;
            }

            $id = $this->stringOrNull($r['id'] ?? null);
            $dedup = $id !== null ? 'id:'.$id : 'title:'.$this->normalize($alt ?? $title);
            if (isset($seen[$dedup])) {
                continue;
            }
            $seen[$dedup] = true;

            $year = $r['releaseYear'] ?? null;

            $releases[] = [
                'id' => $id,
                'alt' => $alt,
                'title' => $title,
                'year' => is_numeric($year) ? (int) $year : null,
                'chapter' => (string) $chapter,
            ];
        }

        return $releases;
    }

    /**
     * Resultado por user_content id:
     *  - release:    lançamento casado (ou null)
     *  - via:        'id' | 'title' | 'fuzzy' | null
     *  - score:      similaridade (só na camada 3)
     *  - candidates: títulos do site quando ambíguo
     *
     * @param  Collection<int, UserContent>  $items  com `content` carregado
     * @return array<int, array{release: ?array, via: ?string, score: ?float, candidates: array<int, string>}>
     */
    public function match(Collection $items, array $releases): array
    {
        $index = $this->buildIndex($releases);
        $results = [];
        $claimed = [];
        $pending = [];

        // Camadas 1 e 2
        foreach ($items as $uc) {
            $i = null;
            $via = null;

            if ($index['hasIds'] && ! empty($uc->site_work_id) && isset($index['byId'][$uc->site_work_id])) {
                $i = $index['byId'][$uc->site_work_id];
                $via = 'id';
            } else {
                $i = $this->exactMatch($uc, $index, $releases);
                $via = $i !== null ? 'title' : null;
            }

            if ($i !== null) {
                $claimed[$i] = true;
            } elseif (! ($index['hasIds'] && ! empty($uc->site_work_id))) {
                // Já vinculado por ID e fora da lista = sem lançamento recente; não chuta.
                $pending[] = $uc;
            }

            $results[$uc->id] = ['release' => $i !== null ? $releases[$i] : null, 'via' => $via, 'score' => null, 'candidates' => []];
        }

        // Camada 3
        foreach ($pending as $uc) {
            [$i, $score, $candidates] = $this->fuzzyMatch($uc, $index, $releases, $claimed);

            if ($i !== null) {
                $claimed[$i] = true;
                $results[$uc->id] = ['release' => $releases[$i], 'via' => 'fuzzy', 'score' => $score, 'candidates' => []];
            } elseif ($candidates) {
                $results[$uc->id]['candidates'] = $candidates;
            }
        }

        return $results;
    }

    /**
     * Normaliza um título para comparação: minúsculas, apóstrofos tipográficos
     * unificados, acentos removidos e apenas alfanuméricos + espaços.
     */
    public function normalize(string $s): string
    {
        $s = str_replace(['’', '‘', '`', '´', '＇', "'"], '', $s);
        $s = mb_strtolower(trim($s));

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($ascii) && $ascii !== '') {
            $s = $ascii;
        }

        $s = preg_replace('/[^a-z0-9]+/i', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim((string) $s));

        return mb_strtolower((string) $s);
    }

    /**
     * Chaves de comparação exata de um título: normalizado e sem artigo inicial.
     *
     * @return array<int, string>
     */
    public function titleKeys(string $title): array
    {
        $key = $this->normalize($title);

        return array_values(array_unique(array_filter(
            [$key, $this->stripArticle($key)],
            fn ($k) => mb_strlen($k) >= 3
        )));
    }

    /** Similaridade (0..1) entre dois títulos — mesma métrica da camada 3. */
    public function titleSimilarity(string $a, string $b): float
    {
        return $this->similarity($this->prepare($a), $this->prepare($b));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Índices do payload: por ID e por título (4 tipos de chave, em ordem de
     * prioridade), mais títulos pré-processados para a camada 3.
     */
    private function buildIndex(array $releases): array
    {
        $index = [
            'byId' => [],
            'byTitle' => ['alt' => [], 'title' => [], 'alt_loose' => [], 'title_loose' => []],
            'prepared' => [],
            'hasIds' => false,
        ];

        foreach ($releases as $i => $r) {
            if ($r['id'] !== null) {
                $index['byId'][$r['id']] ??= $i;
                $index['hasIds'] = true;
            }

            foreach (['alt', 'title'] as $field) {
                if ($r[$field] === null) {
                    continue;
                }
                $key = $this->normalize($r[$field]);
                if (mb_strlen($key) >= 3) {
                    $index['byTitle'][$field][$key][] = $i;
                }
                $loose = $this->stripArticle($key);
                if (mb_strlen($loose) >= 3) {
                    $index['byTitle'][$field.'_loose'][$loose][] = $i;
                }
                $index['prepared'][$i][] = $this->prepare($r[$field]);
            }
        }

        return $index;
    }

    /**
     * Camada 2. Para cada tipo de chave (EN, PT, EN sem artigo, PT sem artigo),
     * percorre os nomes do item em ordem de preferência; o primeiro que casar
     * vence. Mesmo título para obras diferentes: desempata pelo ano mais próximo
     * e, sem ano, fica com o lançamento mais recente (comportamento anterior).
     */
    private function exactMatch(UserContent $uc, array $index, array $releases): ?int
    {
        $names = $this->candidateNames($uc);

        foreach (['alt', 'title', 'alt_loose', 'title_loose'] as $kind) {
            $loose = str_ends_with($kind, '_loose');

            foreach ($names as $name) {
                $key = $this->normalize($name);
                if ($loose) {
                    $key = $this->stripArticle($key);
                }
                if (mb_strlen($key) < 3 || ! isset($index['byTitle'][$kind][$key])) {
                    continue;
                }

                $hits = array_values(array_unique($index['byTitle'][$kind][$key]));

                return count($hits) === 1
                    ? $hits[0]
                    : ($this->closestByYear($hits, $releases, $uc->content?->release_year) ?? $hits[0]);
            }
        }

        return null;
    }

    /**
     * Camada 3. Só para mangá/manhwa/manhua com ano conhecido.
     *
     * @return array{0: ?int, 1: ?float, 2: array<int, string>}  [índice, score, candidatos se ambíguo]
     */
    private function fuzzyMatch(UserContent $uc, array $index, array $releases, array $claimed): array
    {
        $year = $uc->content?->release_year;
        if (! $year || $uc->content?->type !== 'manga') {
            return [null, null, []];
        }

        $names = [];
        foreach ($this->candidateNames($uc) as $name) {
            $p = $this->prepare($name);
            if (strlen($p['key']) >= 3) {
                $names[$p['key']] = $p;
            }
        }
        if (! $names) {
            return [null, null, []];
        }

        $scores = [];
        foreach ($index['prepared'] as $i => $titles) {
            $ry = $releases[$i]['year'];
            if (isset($claimed[$i]) || $ry === null || abs($ry - $year) > self::YEAR_TOLERANCE) {
                continue;
            }

            $best = 0.0;
            foreach ($titles as $t) {
                foreach ($names as $n) {
                    $best = max($best, $this->similarity($n, $t));
                }
            }

            // guarda também os "quase" para medir a vantagem do 1º colocado
            if ($best >= self::FUZZY_MIN - self::FUZZY_GAP) {
                $scores[$i] = $best;
            }
        }

        arsort($scores);
        $top = array_key_first($scores);
        if ($top === null || $scores[$top] < self::FUZZY_MIN) {
            return [null, null, []];
        }

        $values = array_values($scores);
        if (count($values) === 1 || $values[0] - $values[1] >= self::FUZZY_GAP) {
            return [$top, round($scores[$top], 2), []];
        }

        $candidates = array_map(
            fn ($i) => $releases[$i]['alt'] ?? $releases[$i]['title'],
            array_slice(array_keys($scores), 0, 3)
        );

        return [null, null, $candidates];
    }

    /** Nomes do item em ordem de preferência: site_title, nome do catálogo, alternativos. */
    private function candidateNames(UserContent $uc): array
    {
        $names = [];
        if (! empty($uc->site_title)) {
            $names[] = (string) $uc->site_title;
        }
        if (! empty($uc->content?->name)) {
            $names[] = (string) $uc->content->name;
        }

        $alts = $uc->content?->alternative_names;
        if (is_string($alts)) {
            $decoded = json_decode($alts, true);
            $alts = is_array($decoded) ? $decoded : [];
        }
        foreach ((array) $alts as $an) {
            if (is_scalar($an) && (string) $an !== '') {
                $names[] = (string) $an;
            }
        }

        return $names;
    }

    private function closestByYear(array $hits, array $releases, ?int $year): ?int
    {
        if (! $year) {
            return null;
        }

        $diffs = [];
        foreach ($hits as $i) {
            if ($releases[$i]['year'] !== null) {
                $diffs[$i] = abs($releases[$i]['year'] - $year);
            }
        }
        if (! $diffs) {
            return null;
        }

        asort($diffs);
        $best = array_key_first($diffs);
        $tied = count(array_filter($diffs, fn ($d) => $d === $diffs[$best])) > 1;

        return ! $tied && $diffs[$best] <= self::YEAR_TOLERANCE ? $best : null;
    }

    /** @return array{key: string, tokens: array<int, string>} */
    private function prepare(string $title): array
    {
        $key = $this->stripArticle($this->normalize($title));
        $tokens = array_values(array_diff(array_unique(explode(' ', $key)), self::STOPWORDS, ['']));

        return ['key' => $key, 'tokens' => $tokens];
    }

    /**
     * Maior entre Dice sobre palavras (sem stopwords) e Levenshtein relativo.
     * Dice pega palavras a mais/a menos ("...the Reincarnated Bastard..."),
     * Levenshtein pega grafias próximas ("Nano Machine" × "Nano Machines").
     */
    private function similarity(array $a, array $b): float
    {
        $dice = ($a['tokens'] && $b['tokens'])
            ? 2 * count(array_intersect($a['tokens'], $b['tokens'])) / (count($a['tokens']) + count($b['tokens']))
            : 0.0;

        $max = max(strlen($a['key']), strlen($b['key']));
        $lev = $max > 0 ? 1 - levenshtein($a['key'], $b['key']) / $max : 0.0;

        return max($dice, $lev);
    }

    private function stripArticle(string $key): string
    {
        return (string) preg_replace('/^(the|a|an|o|os|as) /', '', $key);
    }

    private function stringOrNull(mixed $v): ?string
    {
        if (! is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
