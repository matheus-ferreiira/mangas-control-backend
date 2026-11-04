<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\UserContent;
use App\Services\ChapterMatchService;
use App\Services\ToonLivreLibraryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

use function Illuminate\Support\defer;

class ChapterCheckController extends Controller
{
    use ApiResponse;

    /** Site quando o payload não diz (os bookmarklets antigos são todos do ToonLivre). */
    private const DEFAULT_SITE = 'toonlivre';

    /** Máximo de obras sem vínculo devolvidas para o bookmarklet procurar no site. */
    private const MAX_CANDIDATES = 200;

    public function __construct(
        private ChapterMatchService $matcher,
        private ToonLivreLibraryService $library,
    ) {}

    /**
     * Recebe os dados coletados pelo app (client-side) e faz o match com a
     * biblioteca do usuário. O fetch ao site é feito no navegador do usuário
     * (cliente real), pois os sites bloqueiam requests server-side.
     *
     *  - `site`:     'toonlivre' (padrão) ou 'tiamanhwa' (config/content.php);
     *  - `releases`: lançamentos do site (último capítulo disponível);
     *  - `reading`:  histórico de leitura do usuário no site (ver
     *                ToonLivreLibraryService) — avança o progresso e traz para a
     *                biblioteca o que só foi lido lá.
     *
     * Só entram itens sem fonte ou já vinculados a este site: um item de outro
     * site nunca muda de fonte por aqui.
     *
     * O vínculo (ver ChapterMatchService) usa, em ordem: o ID da obra no site
     * já salvo, o título exato e, por último, título parecido com ano próximo.
     * Em um casamento, define a fonte como o site, guarda o ID da obra, corrige o
     * site_title para bater exatamente com o site e grava o último capítulo.
     *
     * Obras do histórico que não estão no catálogo dependem da AniList e são
     * importadas depois da resposta (defer); o resultado sai em lastImport().
     */
    public function syncFromClient(Request $request): JsonResponse
    {
        $request->validate([
            'site' => $this->siteRule(),
            'releases' => ['nullable', 'array'],
            'reading' => ['nullable', 'array'],
        ]);

        $userId = (int) auth()->id();
        $site = $this->site($request);
        $siteId = $site['id'];

        $reading = $this->library->parseReading((array) $request->input('reading', []));
        $readingById = array_column($reading, null, 'id');

        // O histórico entra como lançamento (capítulo disponível vindo do site,
        // mais atual que o feed) para usar o mesmo vínculo por ID/título.
        $asReleases = [];
        foreach ($reading as $w) {
            if ($w['available'] !== null) {
                $asReleases[] = ['id' => $w['id'], 'alternativeTitle' => $w['alt'], 'title' => $w['title'], 'alternativeTitles' => $w['alts'], 'releaseYear' => $w['year'], 'chapter' => $w['available']];
            }
        }

        $releases = $this->matcher->parseReleases(array_merge($asReleases, (array) $request->input('releases', [])));
        if (! $releases && ! $reading) {
            return $this->error('Nenhum lançamento ou histórico recebido.', [], 422);
        }
        $payloadHasIds = collect($releases)->contains(fn ($r) => $r['id'] !== null);

        $items = $this->eligibleItems($userId, $siteId);

        $matches = $this->matcher->match($items, $releases);

        $checked = 0;
        $updated = 0;
        $linked = 0;
        $retitled = 0;
        $progressed = 0;
        $newChapters = [];
        $unmatched = [];
        $autoLinked = [];
        $ambiguous = [];

        foreach ($items as $uc) {
            $checked++;
            $match = $matches[$uc->id] ?? null;
            $hit = $match['release'] ?? null;

            if ($hit === null) {
                if (! empty($match['candidates'])) {
                    $ambiguous[] = ['title' => $uc->content?->name, 'candidates' => $match['candidates']];
                } elseif (! ($payloadHasIds && ! empty($uc->site_work_id))) {
                    // Vinculado por ID e fora da lista só significa "sem lançamento recente".
                    $unmatched[] = $uc->content?->name ?? ('#'.$uc->id);
                }

                continue;
            }

            $siteTitle = $hit['alt'] ?? $hit['title'];
            $dirty = false;

            if ($siteId && (int) $uc->site_id !== (int) $siteId) {
                $uc->site_id = $siteId;
                $linked++;
                $dirty = true;
            }
            if ($uc->site_title !== $siteTitle) {
                $uc->site_title = $siteTitle;
                $retitled++;
                $dirty = true;
            }
            if ($hit['id'] !== null && $uc->site_work_id !== $hit['id']) {
                $uc->site_work_id = $hit['id'];
                $dirty = true;
            }
            if ((string) $uc->site_last_chapter !== $hit['chapter']) {
                $uc->site_last_chapter = $hit['chapter'];
                $dirty = true;
            }

            if ($dirty) {
                $uc->save();
            }
            $updated++;

            if ($match['via'] === 'fuzzy') {
                $autoLinked[] = [
                    'title' => $uc->content?->name,
                    'site_title' => $siteTitle,
                    'score' => $match['score'],
                ];
            }
        }

        // Caso 0: histórico de obras já na biblioteca → avança o progresso.
        $byWorkId = $items
            ->filter(fn ($u) => ! empty($u->site_work_id) && (int) $u->site_id === (int) $siteId)
            ->keyBy('site_work_id');
        $missing = [];

        foreach ($reading as $w) {
            $uc = $byWorkId[$w['id']] ?? null;
            if (! $uc) {
                $missing[] = $w;

                continue;
            }
            if ($this->library->applyProgress($uc, $w)) {
                $uc->save();
                $progressed++;
            }
        }

        foreach ($items as $uc) {
            $seen = isset($matches[$uc->id]['release']) || isset($readingById[$uc->site_work_id ?? '']);
            if ($seen && $uc->site_last_chapter !== null && $this->toFloat($uc->site_last_chapter) > $this->toFloat($uc->current_units)) {
                $newChapters[] = [
                    'title' => $uc->content?->name,
                    'site_title' => $uc->site_title,
                    'current' => (int) $uc->current_units,
                    'available' => (string) $uc->site_last_chapter,
                ];
            }
        }

        // Caso A: está no catálogo, só não estava na biblioteca (só banco, síncrono).
        $added = [];
        $toImport = [];
        foreach ($missing as $w) {
            $content = $this->library->findInCatalog($w);
            if ($content) {
                $this->library->addToLibrary($userId, $content, $w, $siteId);
                $added[] = ['title' => $content->name, 'site_title' => $w['alt'] ?? $w['title']];
            } else {
                $toImport[] = $w;
            }
        }

        $lastImport = Cache::get($this->importCacheKey($site, $userId));

        // Caso B: AniList / dados do site, depois da resposta.
        if ($toImport) {
            $this->queueImport($userId, $toImport, $site);
        }

        $totalLinked = $siteId
            ? UserContent::where('user_id', $userId)->where('site_id', $siteId)->count()
            : 0;

        return $this->success([
            'site' => $site['key'],
            'checked' => $checked,
            'updated' => $updated,
            'linked' => $linked,
            'total_linked' => $totalLinked,
            'retitled' => $retitled,
            'progressed' => $progressed,
            'new_chapters' => $newChapters,
            'unmatched' => $unmatched,
            'auto_linked' => $autoLinked,
            'ambiguous' => $ambiguous,
            'added' => $added,
            'importing' => array_map(fn ($w) => $w['alt'] ?? $w['title'], $toImport),
            'last_import' => $lastImport,
        ], 'Sincronização concluída.');
    }

    /**
     * Para sites cuja listagem não traz o título em inglês (TiaManhwa): itens
     * de mangá da biblioteca ainda sem vínculo, com os nomes a procurar na busca
     * do site, e os IDs de obra já vinculados (esses o bookmarklet não procura).
     */
    public function candidates(Request $request): JsonResponse
    {
        $request->validate(['site' => $this->siteRule()]);

        $site = $this->site($request);
        $items = $this->eligibleItems((int) auth()->id(), $site['id']);

        $linked = $items
            ->filter(fn ($u) => ! empty($u->site_work_id) && (int) $u->site_id === (int) $site['id'])
            ->pluck('site_work_id')
            ->values();

        $unlinked = $items
            ->filter(fn ($u) => empty($u->site_work_id) && $u->content?->type === 'manga')
            ->take(self::MAX_CANDIDATES)
            ->map(fn ($u) => [
                'id' => $u->id,
                'title' => $u->content->name,
                'names' => $this->matcher->searchNames($u),
                'year' => $u->content->release_year,
                'adult' => (bool) $u->content->is_adult,
            ])
            ->filter(fn ($c) => $c['names'])
            ->values();

        return $this->success([
            'site' => $site['key'],
            'linked' => $linked,
            'unlinked' => $unlinked,
        ]);
    }

    /** Resultado da última importação em segundo plano (`?site=`, padrão ToonLivre). */
    public function lastImport(Request $request): JsonResponse
    {
        $request->validate(['site' => $this->siteRule()]);

        return $this->success(Cache::get($this->importCacheKey($this->site($request), (int) auth()->id())));
    }

    /**
     * Importa depois de enviar a resposta (php-fpm: fastcgi_finish_request), sem
     * depender de fila. O lock evita dois imports simultâneos do mesmo usuário
     * no mesmo site; o que não entrar agora é tentado de novo no próximo sync.
     */
    private function queueImport(int $userId, array $works, array $site): void
    {
        $key = $this->importCacheKey($site, $userId);

        defer(function () use ($userId, $works, $site, $key) {
            $lock = Cache::lock($key.':lock', 1800);
            if (! $lock->get()) {
                return;
            }

            try {
                @set_time_limit(0);
                ignore_user_abort(true);

                Cache::put($key, ['status' => 'running', 'started_at' => now()->toIso8601String(), 'total' => count($works)], now()->addDays(7));

                $results = $this->library->importMissing($userId, $works, $site);

                Cache::put($key, [
                    'status' => 'done',
                    'finished_at' => now()->toIso8601String(),
                    'total' => count($results),
                    'results' => $results,
                ], now()->addDays(7));
            } finally {
                $lock->release();
            }
        });
    }

    /**
     * Itens que o sync do site pode tocar: mangás (os sites só têm mangá/manhwa)
     * sem fonte ou já deste site. Sem o filtro de tipo, uma novel com os mesmos
     * nomes do manhwa (ex.: The Swordmaster's Son) casaria com o lançamento dele.
     */
    private function eligibleItems(int $userId, ?int $siteId): Collection
    {
        return UserContent::with('content')
            ->where('user_id', $userId)
            ->whereHas('content', fn ($q) => $q->where('type', 'manga'))
            ->where(fn ($q) => $siteId
                ? $q->whereNull('site_id')->orWhere('site_id', $siteId)
                : $q->whereNull('site_id'))
            ->get();
    }

    /** @return array{key: string, id: ?int, adult: bool} */
    private function site(Request $request): array
    {
        $key = (string) ($request->input('site') ?: self::DEFAULT_SITE);
        $config = config('content.reading_sites.'.$key);

        return [
            'key' => $key,
            'id' => optional(Site::where('url', 'like', $config['url_like'])->first())->id,
            'adult' => (bool) $config['adult'],
        ];
    }

    private function siteRule(): array
    {
        return ['nullable', 'string', Rule::in(array_keys(config('content.reading_sites')))];
    }

    /** Ex.: "toonlivre-import:1" (mesma chave de antes para o ToonLivre). */
    private function importCacheKey(array $site, int $userId): string
    {
        return $site['key'].'-import:'.$userId;
    }

    private function toFloat(mixed $v): float
    {
        return (float) str_replace(',', '.', (string) $v);
    }
}
