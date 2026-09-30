<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\UserContent;
use App\Services\ChapterMatchService;
use App\Services\ToonLivreLibraryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\defer;

class ChapterCheckController extends Controller
{
    use ApiResponse;

    /** Resultado da última importação em segundo plano (por usuário). */
    private const IMPORT_CACHE = 'toonlivre-import:';

    public function __construct(
        private ChapterMatchService $matcher,
        private ToonLivreLibraryService $library,
    ) {}

    /**
     * Recebe os dados coletados pelo app (client-side) e faz o match com a
     * biblioteca do usuário. O fetch ao site é feito no navegador do usuário
     * (cliente real), pois o site bloqueia requests server-side.
     *
     *  - `releases`: lançamentos recentes do site (último capítulo disponível);
     *  - `reading`:  histórico de leitura do usuário no site (ver
     *                ToonLivreLibraryService) — avança o progresso e traz para a
     *                biblioteca o que só foi lido no ToonLivre.
     *
     * O vínculo (ver ChapterMatchService) usa, em ordem: o ID da obra no site
     * já salvo, o título exato em inglês/português e, por último, título
     * parecido com ano próximo. Em um casamento, define a fonte como
     * ToonLivre, guarda o ID da obra, corrige o site_title para bater
     * exatamente com o site e grava o último capítulo disponível.
     *
     * Obras do histórico que não estão no catálogo dependem da AniList e são
     * importadas depois da resposta (defer); o resultado sai em lastImport().
     */
    public function syncFromClient(Request $request): JsonResponse
    {
        $request->validate([
            'releases' => ['nullable', 'array'],
            'reading' => ['nullable', 'array'],
        ]);

        $userId = (int) auth()->id();
        $toonId = optional(Site::where('url', 'like', '%toonlivre%')->first())->id;

        $reading = $this->library->parseReading((array) $request->input('reading', []));
        $readingById = array_column($reading, null, 'id');

        // O histórico entra como lançamento (capítulo disponível vindo do by-ids,
        // mais atual que o feed) para usar o mesmo vínculo por ID/título.
        $asReleases = [];
        foreach ($reading as $w) {
            if ($w['available'] !== null) {
                $asReleases[] = ['id' => $w['id'], 'alternativeTitle' => $w['alt'], 'title' => $w['title'], 'releaseYear' => $w['year'], 'chapter' => $w['available']];
            }
        }

        $releases = $this->matcher->parseReleases(array_merge($asReleases, (array) $request->input('releases', [])));
        if (! $releases && ! $reading) {
            return $this->error('Nenhum lançamento ou histórico recebido.', [], 422);
        }
        $payloadHasIds = collect($releases)->contains(fn ($r) => $r['id'] !== null);

        $items = UserContent::with('content')
            ->where('user_id', $userId)
            ->get();

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

            if ($toonId && (int) $uc->site_id !== (int) $toonId) {
                $uc->site_id = $toonId;
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
        $byWorkId = $items->filter(fn ($u) => ! empty($u->site_work_id))->keyBy('site_work_id');
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
                $this->library->addToLibrary($userId, $content, $w, $toonId);
                $added[] = ['title' => $content->name, 'site_title' => $w['alt'] ?? $w['title']];
            } else {
                $toImport[] = $w;
            }
        }

        $lastImport = Cache::get(self::IMPORT_CACHE.$userId);

        // Caso B: AniList / dados do ToonLivre, depois da resposta.
        if ($toImport) {
            $this->queueImport($userId, $toImport, $toonId);
        }

        $totalLinked = $toonId
            ? UserContent::where('user_id', $userId)->where('site_id', $toonId)->count()
            : 0;

        return $this->success([
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

    /** Resultado da última importação em segundo plano. */
    public function lastImport(): JsonResponse
    {
        return $this->success(Cache::get(self::IMPORT_CACHE.auth()->id()));
    }

    /**
     * Importa depois de enviar a resposta (php-fpm: fastcgi_finish_request), sem
     * depender de fila. O lock evita dois imports simultâneos do mesmo usuário;
     * o que não entrar agora é tentado de novo no próximo sync.
     */
    private function queueImport(int $userId, array $works, ?int $toonId): void
    {
        $key = self::IMPORT_CACHE.$userId;

        defer(function () use ($userId, $works, $toonId, $key) {
            $lock = Cache::lock($key.':lock', 1800);
            if (! $lock->get()) {
                return;
            }

            try {
                @set_time_limit(0);
                ignore_user_abort(true);

                Cache::put($key, ['status' => 'running', 'started_at' => now()->toIso8601String(), 'total' => count($works)], now()->addDays(7));

                $results = $this->library->importMissing($userId, $works, $toonId);

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

    private function toFloat(mixed $v): float
    {
        return (float) str_replace(',', '.', (string) $v);
    }
}
