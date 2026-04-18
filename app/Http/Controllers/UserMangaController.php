<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserMangaRequest;
use App\Http\Requests\UpdateUserMangaRequest;
use App\Http\Resources\UserMangaResource;
use App\Models\UserManga;
use App\Services\UserMangaService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserMangaController extends Controller
{
    use ApiResponse;

    public function __construct(private UserMangaService $userMangaService) {}

    public function index(Request $request): JsonResponse
    {
        $userMangas = $this->userMangaService->getUserMangas(
            $request->user()->id,
            $request->only(['status', 'per_page'])
        );

        return $this->success(UserMangaResource::collection($userMangas)->response()->getData(true));
    }

    public function store(StoreUserMangaRequest $request): JsonResponse
    {
        $userManga = $this->userMangaService->create(
            $request->user()->id,
            $request->validated()
        );

        return $this->success(new UserMangaResource($userManga), 'Manga adicionado à lista', 201);
    }

    public function show(Request $request, UserManga $userManga): JsonResponse
    {
        $this->ensureOwnership($request->user()->id, $userManga);

        return $this->success(new UserMangaResource($userManga->load(['manga', 'site'])));
    }

    public function update(UpdateUserMangaRequest $request, UserManga $userManga): JsonResponse
    {
        $this->ensureOwnership($request->user()->id, $userManga);

        $userManga->update($request->validated());

        return $this->success(
            new UserMangaResource($userManga->fresh(['manga', 'site'])),
            'Atualizado com sucesso'
        );
    }

    public function increment(Request $request, UserManga $userManga): JsonResponse
    {
        $this->ensureOwnership($request->user()->id, $userManga);

        $userManga->increment('current_chapters');

        return $this->success(
            new UserMangaResource($userManga->fresh(['manga', 'site'])),
            'Capítulo incrementado'
        );
    }

    public function destroy(Request $request, UserManga $userManga): JsonResponse
    {
        $this->ensureOwnership($request->user()->id, $userManga);

        $userManga->delete();

        return $this->success(null, 'Manga removido da lista');
    }

    private function ensureOwnership(int $userId, UserManga $userManga): void
    {
        if ($userManga->user_id !== $userId) {
            abort(403, 'Acesso negado');
        }
    }
}
