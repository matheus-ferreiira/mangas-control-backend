<?php

namespace App\Http\Controllers;

use App\Helpers\LogHelper;
use App\Helpers\NameHelper;
use App\Http\Requests\StoreContentRequest;
use App\Http\Requests\UpdateContentRequest;
use App\Http\Resources\ContentResource;
use App\Models\Content;
use App\Services\ContentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ContentController extends Controller
{
    use ApiResponse;

    // Chave de versão: incrementada em CUD para invalidar todas as caches de listagem
    private const CACHE_VERSION_KEY = 'contents.cache_version';
    private const CACHE_TTL         = 60; // segundos

    public function __construct(private ContentService $contentService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'type', 'status', 'search', 'genres', 'year', 'year_min', 'year_max',
            'sort', 'order', 'per_page', 'recent',
            'rating_min', 'rating_max', 'votes_min',
            'language', 'country', 'is_adult',
        ]);

        $userId   = auth()->id();
        $version  = Cache::get(self::CACHE_VERSION_KEY, 0);
        $cacheKey = "api.contents.v{$version}.u{$userId}." . md5(json_encode($filters) . '_p' . $request->get('page', 1));

        $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($filters, $request, $userId) {
            $result = $this->contentService->getContents($filters, $userId);

            return [
                'items' => collect($result->items())
                    ->map(fn ($c) => (new ContentResource($c))->toArray($request))
                    ->values()
                    ->all(),
                'meta'  => [
                    'current_page' => $result->currentPage(),
                    'last_page'    => $result->lastPage(),
                    'per_page'     => $result->perPage(),
                    'total'        => $result->total(),
                    'from'         => $result->firstItem(),
                    'to'           => $result->lastItem(),
                ],
            ];
        });

        return $this->success($data);
    }

    public function store(StoreContentRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['alternative_names'])) {
            $data['alternative_names'] = NameHelper::normalizeList($data['alternative_names']);
        }

        if ($this->contentService->isDuplicate($data['name'], $data['alternative_names'] ?? [])) {
            return $this->error('Já existe um conteúdo com este nome ou nomes alternativos', [], 422);
        }

        if ($request->hasFile('cover')) {
            $data['cover'] = $request->file('cover')->store('covers', 'public');
        }

        $content = Content::create($data);

        Cache::increment(self::CACHE_VERSION_KEY);

        LogHelper::info('Conteúdo criado', [
            'content_id' => $content->id,
            'name'       => $content->name,
            'type'       => $content->type,
            'has_cover'  => (bool) $content->cover,
        ]);

        return $this->success(new ContentResource($content), 'Conteúdo criado com sucesso', 201);
    }

    public function show(int $id): JsonResponse
    {
        $userId  = auth()->id();
        $content = Content::selectRaw(
            'contents.*, EXISTS(SELECT 1 FROM user_contents WHERE content_id = contents.id AND user_id = ?) as is_in_library',
            [(int) $userId]
        )->find($id);

        if (! $content) {
            return $this->error('Conteúdo não encontrado', [], 404);
        }

        return $this->success(new ContentResource($content));
    }

    public function update(UpdateContentRequest $request, int $id): JsonResponse
    {
        $content = Content::find($id);

        if (! $content) {
            return $this->error('Conteúdo não encontrado', [], 404);
        }

        $data = $request->validated();

        if (isset($data['alternative_names'])) {
            $data['alternative_names'] = NameHelper::normalizeList($data['alternative_names']);
        }

        $checkName = $data['name'] ?? $content->name;
        $checkAlts = $data['alternative_names'] ?? $content->alternative_names ?? [];

        if ($this->contentService->isDuplicate($checkName, $checkAlts, $id)) {
            return $this->error('Já existe um conteúdo com este nome ou nomes alternativos', [], 422);
        }

        if ($request->hasFile('cover')) {
            if ($content->cover && ! str_starts_with($content->cover, 'http')) {
                Storage::disk('public')->delete($content->cover);
            }
            $data['cover'] = $request->file('cover')->store('covers', 'public');
        }

        $content->update($data);

        Cache::increment(self::CACHE_VERSION_KEY);

        LogHelper::info('Conteúdo atualizado', [
            'content_id' => $content->id,
            'fields'     => array_keys($data),
        ]);

        return $this->success(new ContentResource($content), 'Conteúdo atualizado com sucesso');
    }

    public function destroy(int $id): JsonResponse
    {
        $content = Content::find($id);

        if (! $content) {
            return $this->error('Conteúdo não encontrado', [], 404);
        }

        if ($content->cover && ! str_starts_with($content->cover, 'http')) {
            Storage::disk('public')->delete($content->cover);
        }

        $content->delete();

        Cache::increment(self::CACHE_VERSION_KEY);

        LogHelper::info('Conteúdo removido', [
            'content_id' => $id,
            'name'       => $content->name,
        ]);

        return $this->success(null, 'Conteúdo removido com sucesso');
    }
}
