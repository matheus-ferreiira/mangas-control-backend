<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Services\NovelReaderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NovelReaderController extends Controller
{
    use ApiResponse;

    public function __construct(private NovelReaderService $reader) {}

    public function index(int $id): JsonResponse
    {
        $edition = $this->edition($id);

        return $this->success($this->reader->index($edition['slug']))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(int $id, int $chapter): JsonResponse
    {
        $edition = $this->edition($id);
        $data = $this->reader->chapter($edition['slug'], $chapter);
        $data['browser_url'] = $this->reader->browserUrl($edition['slug'], $chapter);

        return $this->success($data)->header('Cache-Control', 'private, no-store');
    }

    public function browser(Request $request, string $slug, int $chapter): Response
    {
        $index = $this->reader->index($slug);
        $data = $this->reader->chapter($slug, $chapter);
        $position = array_search($chapter, $index['chapters'], true);
        $previous = $index['chapters'][$position - 1] ?? null;
        $next = $index['chapters'][$position + 1] ?? null;
        $expires = (int) $request->query('expires');

        return response()->view('novel-reader', [
            'novel' => $index, 'chapter' => $data,
            'previousUrl' => $previous ? $this->reader->browserUrl($slug, $previous, $expires) : null,
            'nextUrl' => $next ? $this->reader->browserUrl($slug, $next, $expires) : null,
        ])->withHeaders([
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    private function edition(int $id): array
    {
        $content = Content::forAudience()->findOrFail($id);
        $edition = $this->reader->metadata($content);
        abort_unless($edition, 404, 'Leitor indisponível para esta obra.');

        return $edition;
    }
}
