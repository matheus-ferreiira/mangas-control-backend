<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\UserContent;
use App\Services\ChapterMatchService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChapterCheckController extends Controller
{
    use ApiResponse;

    public function __construct(private ChapterMatchService $matcher) {}

    /**
     * Recebe os lançamentos coletados pelo app (client-side) e faz o match
     * com a biblioteca do usuário. O fetch ao site é feito no navegador do
     * usuário (cliente real), pois o site bloqueia requests server-side.
     *
     * O vínculo (ver ChapterMatchService) usa, em ordem: o ID da obra no site
     * já salvo, o título exato em inglês/português e, por último, título
     * parecido com ano próximo. Em um casamento, define a fonte como
     * ToonLivre, guarda o ID da obra, corrige o site_title para bater
     * exatamente com o site e grava o último capítulo disponível.
     */
    public function syncFromClient(Request $request): JsonResponse
    {
        $request->validate([
            'releases' => ['required', 'array', 'min:1'],
        ]);

        $toonId = optional(Site::where('url', 'like', '%toonlivre%')->first())->id;

        $releases = $this->matcher->parseReleases((array) $request->input('releases', []));
        $payloadHasIds = collect($releases)->contains(fn ($r) => $r['id'] !== null);

        $items = UserContent::with('content')
            ->where('user_id', auth()->id())
            ->get();

        $matches = $this->matcher->match($items, $releases);

        $checked = 0;
        $updated = 0;
        $linked = 0;
        $retitled = 0;
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

            if ($this->toFloat($hit['chapter']) > $this->toFloat($uc->current_units)) {
                $newChapters[] = [
                    'title' => $uc->content?->name,
                    'site_title' => $uc->site_title,
                    'current' => (int) $uc->current_units,
                    'available' => $hit['chapter'],
                ];
            }
        }

        $totalLinked = $toonId
            ? $items->filter(fn ($u) => (int) $u->site_id === (int) $toonId)->count()
            : 0;

        return $this->success([
            'checked' => $checked,
            'updated' => $updated,
            'linked' => $linked,
            'total_linked' => $totalLinked,
            'retitled' => $retitled,
            'new_chapters' => $newChapters,
            'unmatched' => $unmatched,
            'auto_linked' => $autoLinked,
            'ambiguous' => $ambiguous,
        ], 'Sincronização concluída.');
    }

    private function toFloat(mixed $v): float
    {
        return (float) str_replace(',', '.', (string) $v);
    }
}
