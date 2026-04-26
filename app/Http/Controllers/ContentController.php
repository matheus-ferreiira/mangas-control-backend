<?php

namespace App\Http\Controllers;

use App\Helpers\LogHelper;
use App\Http\Requests\StoreContentRequest;
use App\Http\Requests\UpdateContentRequest;
use App\Http\Resources\ContentResource;
use App\Models\Content;
use App\Services\ContentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContentController extends Controller
{
    use ApiResponse;

    public function __construct(private ContentService $contentService) {}

    public function index(Request $request): JsonResponse
    {
        $contents = $this->contentService->getContents(
            $request->only(['type', 'status', 'search', 'recent'])
        );

        return $this->success(ContentResource::collection($contents));
    }

    public function store(StoreContentRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($this->contentService->isDuplicate($data['name'], $data['alternative_names'] ?? [])) {
            return $this->error('Já existe um conteúdo com este nome ou nomes alternativos', [], 422);
        }

        if ($request->hasFile('cover')) {
            $data['cover'] = $request->file('cover')->store('covers', 'public');
        }

        $content = Content::create($data);

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
        $content = Content::find($id);

        if (!$content) {
            return $this->error('Conteúdo não encontrado', [], 404);
        }

        return $this->success(new ContentResource($content));
    }

    public function update(UpdateContentRequest $request, int $id): JsonResponse
    {
        $content = Content::find($id);

        if (!$content) {
            return $this->error('Conteúdo não encontrado', [], 404);
        }

        $data = $request->validated();

        $checkName = $data['name'] ?? $content->name;
        $checkAlts = $data['alternative_names'] ?? $content->alternative_names ?? [];

        if ($this->contentService->isDuplicate($checkName, $checkAlts, $id)) {
            return $this->error('Já existe um conteúdo com este nome ou nomes alternativos', [], 422);
        }

        if ($request->hasFile('cover')) {
            if ($content->cover) {
                Storage::disk('public')->delete($content->cover);
            }
            $data['cover'] = $request->file('cover')->store('covers', 'public');
        }

        $content->update($data);

        LogHelper::info('Conteúdo atualizado', [
            'content_id' => $content->id,
            'fields'     => array_keys($data),
        ]);

        return $this->success(new ContentResource($content), 'Conteúdo atualizado com sucesso');
    }

    public function destroy(int $id): JsonResponse
    {
        $content = Content::find($id);

        if (!$content) {
            return $this->error('Conteúdo não encontrado', [], 404);
        }

        if ($content->cover) {
            Storage::disk('public')->delete($content->cover);
        }

        $content->delete();

        LogHelper::info('Conteúdo removido', [
            'content_id' => $id,
            'name'       => $content->name,
        ]);

        return $this->success(null, 'Conteúdo removido com sucesso');
    }
}
