<?php

namespace App\Http\Controllers;

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
        $contents = $this->contentService->getContents($request->only(['type', 'search']));

        return $this->success(ContentResource::collection($contents));
    }

    public function store(StoreContentRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($request->hasFile('cover')) {
            if ($request->hasFile('cover')) {
                $supabase = new SupabaseStorageService();
                $data['cover'] = $supabase->upload($request->file('cover'));
            }
        }

        $content = Content::create($data);

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

        if ($request->hasFile('cover')) {
            if ($content->cover) {
                $supabase = new SupabaseStorageService();
            }
            $data['cover'] = $supabase->upload($request->file('cover'));
        }

        $content->update($data);

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

        return $this->success(null, 'Conteúdo removido com sucesso');
    }
}
