<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMangaRequest;
use App\Http\Requests\UpdateMangaRequest;
use App\Http\Resources\MangaResource;
use App\Models\Manga;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MangaController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $mangas = Manga::query()
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->paginate($request->integer('per_page', 15));

        return $this->success(MangaResource::collection($mangas)->response()->getData(true));
    }

    public function store(StoreMangaRequest $request): JsonResponse
    {
        $manga = Manga::create($request->validated());

        return $this->success(new MangaResource($manga), 'Manga criado com sucesso', 201);
    }

    public function show(Manga $manga): JsonResponse
    {
        return $this->success(new MangaResource($manga));
    }

    public function update(UpdateMangaRequest $request, Manga $manga): JsonResponse
    {
        $manga->update($request->validated());

        return $this->success(new MangaResource($manga), 'Manga atualizado com sucesso');
    }

    public function destroy(Manga $manga): JsonResponse
    {
        $manga->delete();

        return $this->success(null, 'Manga removido com sucesso');
    }
}
