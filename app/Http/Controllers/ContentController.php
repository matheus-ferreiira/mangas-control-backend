<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContentResource;
use App\Models\Content;
use App\Services\ContentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ContentController extends Controller
{
    use ApiResponse;

    // Chave de versão: incrementada em CUD para invalidar todas as caches de listagem
    private const CACHE_VERSION_KEY = 'contents.cache_version';

    private const CACHE_TTL = 60; // segundos

    public function __construct(private ContentService $contentService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'type', 'origin_type', 'format', 'status', 'search', 'genres', 'year', 'year_min', 'year_max',
            'sort', 'order', 'per_page', 'recent',
            'rating_min', 'rating_max', 'votes_min',
            'language', 'country', 'is_adult',
        ]);

        $userId = auth()->id();
        $showAdult = (int) (bool) (optional(auth()->user())->show_adult_content ?? false);
        $version = Cache::get(self::CACHE_VERSION_KEY, 0);
        $cacheKey = "api.contents.v{$version}.u{$userId}.a{$showAdult}.".md5(json_encode($filters).'_p'.$request->get('page', 1));

        $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($filters, $request, $userId) {
            $result = $this->contentService->getContents($filters, $userId);

            return [
                'items' => collect($result->items())
                    ->map(fn ($c) => (new ContentResource($c))->toArray($request))
                    ->values()
                    ->all(),
                'meta' => [
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                    'from' => $result->firstItem(),
                    'to' => $result->lastItem(),
                ],
            ];
        });

        return $this->success($data);
    }

    public function show(int $id): JsonResponse
    {
        $userId = auth()->id();
        $content = Content::selectRaw(
            'contents.*, EXISTS(SELECT 1 FROM user_contents WHERE content_id = contents.id AND user_id = ?) as is_in_library',
            [(int) $userId]
        )->find($id);

        if (! $content) {
            return $this->error('Conteúdo não encontrado', [], 404);
        }

        return $this->success(new ContentResource($content));
    }
}
