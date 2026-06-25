<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\UserContent;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChapterCheckController extends Controller
{
    use ApiResponse;

    /**
     * Recebe os lançamentos coletados pelo app (client-side) e faz o match
     * com a biblioteca do usuário. O fetch ao site é feito no navegador do
     * usuário (cliente real), pois o site bloqueia requests server-side.
     *
     * O match é tolerante a diferenças de pontuação/apóstrofo/acento/caixa,
     * e compara contra o título do site (site_title), o nome do catálogo e
     * os nomes alternativos. Em um casamento, define a fonte como ToonLivre,
     * corrige o site_title para bater exatamente com o site, e grava o
     * último capítulo disponível.
     */
    public function syncFromClient(Request $request): JsonResponse
    {
        $request->validate([
            'releases' => ['required', 'array', 'min:1'],
        ]);

        $toonId = optional(Site::where('url', 'like', '%toonlivre%')->first())->id;

        // Mapa normalizado: chave => ['title' => exato, 'chapter' => string]
        // Mantém a primeira ocorrência (mais recente).
        $map = [];
        foreach ((array) $request->input('releases', []) as $r) {
            if (! is_array($r)) {
                continue;
            }
            $title = $r['alternativeTitle'] ?? null;
            $chapter = $r['chapter'] ?? null;
            if (! is_scalar($title) || $chapter === null || $chapter === '') {
                continue;
            }
            $key = $this->normalize((string) $title);
            if ($key === '' || array_key_exists($key, $map)) {
                continue;
            }
            $map[$key] = ['title' => (string) $title, 'chapter' => (string) $chapter];
        }

        $items = UserContent::with('content')
            ->where('user_id', auth()->id())
            ->get();

        $checked = 0;
        $updated = 0;
        $linked = 0;
        $retitled = 0;
        $newChapters = [];
        $unmatched = [];

        foreach ($items as $uc) {
            $checked++;

            // Candidatos de título, em ordem de preferência.
            $candidates = [];
            if (! empty($uc->site_title)) {
                $candidates[] = $uc->site_title;
            }
            if (! empty($uc->content?->name)) {
                $candidates[] = $uc->content->name;
            }
            $alts = $uc->content?->alternative_names;
            if (is_string($alts)) {
                $decoded = json_decode($alts, true);
                $alts = is_array($decoded) ? $decoded : [];
            }
            foreach ((array) $alts as $an) {
                if (is_scalar($an)) {
                    $candidates[] = (string) $an;
                }
            }

            $hit = null;
            foreach ($candidates as $c) {
                $key = $this->normalize((string) $c);
                if (mb_strlen($key) < 3) {
                    continue;
                }
                if (isset($map[$key])) {
                    $hit = $map[$key];
                    break;
                }
            }

            if ($hit === null) {
                $unmatched[] = $uc->content?->name ?? ('#'.$uc->id);

                continue;
            }

            $dirty = false;

            if ($toonId && (int) $uc->site_id !== (int) $toonId) {
                $uc->site_id = $toonId;
                $linked++;
                $dirty = true;
            }
            if ($uc->site_title !== $hit['title']) {
                $uc->site_title = $hit['title'];
                $retitled++;
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
        ], 'Sincronização concluída.');
    }

    /**
     * Normaliza um título para comparação: minúsculas, apóstrofos tipográficos
     * unificados, acentos removidos e apenas alfanuméricos + espaços.
     */
    private function normalize(string $s): string
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

    private function toFloat(mixed $v): float
    {
        return (float) str_replace(',', '.', (string) $v);
    }
}
