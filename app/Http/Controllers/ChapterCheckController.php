<?php

namespace App\Http\Controllers;

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
     */
    public function syncFromClient(Request $request): JsonResponse
    {
        $request->validate([
            'releases' => ['required', 'array', 'min:1'],
        ]);

        // Mapa título(lower/trim) => capítulo (tolerante: ignora itens malformados;
        // mantém a primeira ocorrência = mais recente)
        $map = [];
        foreach ((array) $request->input('releases', []) as $r) {
            $title = is_array($r) ? ($r['alternativeTitle'] ?? null) : null;
            $chapter = is_array($r) ? ($r['chapter'] ?? null) : null;
            if (! is_scalar($title) || $chapter === null || $chapter === '') {
                continue;
            }
            $key = mb_strtolower(trim((string) $title));
            if ($key === '' || array_key_exists($key, $map)) {
                continue;
            }
            $map[$key] = (string) $chapter;
        }

        $items = UserContent::with('content')
            ->where('user_id', auth()->id())
            ->whereNotNull('site_title')
            ->where('site_title', '!=', '')
            ->get();

        $checked = 0;
        $updated = 0;
        $newChapters = [];

        foreach ($items as $uc) {
            $checked++;
            $key = mb_strtolower(trim((string) $uc->site_title));
            $chapter = $map[$key] ?? null;
            if ($chapter === null) {
                continue;
            }

            $uc->site_last_chapter = $chapter;
            $uc->save();
            $updated++;

            if ($this->toFloat($chapter) > $this->toFloat($uc->current_units)) {
                $newChapters[] = [
                    'title' => $uc->content?->name,
                    'site_title' => $uc->site_title,
                    'current' => (int) $uc->current_units,
                    'available' => $chapter,
                ];
            }
        }

        return $this->success([
            'checked' => $checked,
            'updated' => $updated,
            'new_chapters' => $newChapters,
        ], 'Sincronização concluída.');
    }

    private function toFloat(mixed $v): float
    {
        return (float) str_replace(',', '.', (string) $v);
    }
}
