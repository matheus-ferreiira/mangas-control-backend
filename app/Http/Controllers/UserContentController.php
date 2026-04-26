<?php

namespace App\Http\Controllers;

use App\Helpers\LogHelper;
use App\Http\Requests\StoreUserContentRequest;
use App\Http\Requests\UpdateUserContentRequest;
use App\Http\Resources\UserContentResource;
use App\Models\UserContent;
use App\Services\UserContentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserContentController extends Controller
{
    use ApiResponse;

    public function __construct(private UserContentService $userContentService) {}

    public function index(Request $request): JsonResponse
    {
        $userContents = $this->userContentService->getUserContents(
            auth()->id(),
            $request->only(['type', 'status'])
        );

        return $this->success(UserContentResource::collection($userContents));
    }

    public function store(StoreUserContentRequest $request): JsonResponse
    {
        $userContent = $this->userContentService->create(auth()->id(), $request->validated());

        return $this->success(new UserContentResource($userContent), 'Conteúdo adicionado à biblioteca', 201);
    }

    public function show(int $id): JsonResponse
    {
        $userContent = UserContent::with(['content', 'site'])->find($id);

        if (!$userContent) {
            return $this->error('Item não encontrado', [], 404);
        }

        $this->ensureOwnership($userContent);

        return $this->success(new UserContentResource($userContent));
    }

    public function update(UpdateUserContentRequest $request, int $id): JsonResponse
    {
        $userContent = UserContent::find($id);

        if (!$userContent) {
            return $this->error('Item não encontrado', [], 404);
        }

        $this->ensureOwnership($userContent);

        $userContent->update($request->validated());
        $userContent->load(['content', 'site']);

        LogHelper::info('Item da biblioteca atualizado', [
            'user_content_id' => $userContent->id,
            'content_id'      => $userContent->content_id,
            'fields'          => array_keys($request->validated()),
        ]);

        return $this->success(new UserContentResource($userContent), 'Item atualizado com sucesso');
    }

    public function increment(int $id): JsonResponse
    {
        $userContent = UserContent::find($id);

        if (!$userContent) {
            return $this->error('Item não encontrado', [], 404);
        }

        $this->ensureOwnership($userContent);

        $previous = $userContent->current_units;
        $userContent->increment('current_units');
        $userContent->refresh()->load(['content', 'site']);

        LogHelper::info('Progresso incrementado', [
            'user_content_id' => $userContent->id,
            'content_id'      => $userContent->content_id,
            'previous'        => $previous,
            'current'         => $userContent->current_units,
        ]);

        return $this->success(new UserContentResource($userContent), 'Progresso atualizado');
    }

    public function destroy(int $id): JsonResponse
    {
        $userContent = UserContent::find($id);

        if (!$userContent) {
            return $this->error('Item não encontrado', [], 404);
        }

        $this->ensureOwnership($userContent);

        $userContent->delete();

        LogHelper::info('Item removido da biblioteca', [
            'user_content_id' => $id,
            'content_id'      => $userContent->content_id,
        ]);

        return $this->success(null, 'Item removido da biblioteca');
    }

    private function ensureOwnership(UserContent $userContent): void
    {
        if ($userContent->user_id !== auth()->id()) {
            abort(403, 'Acesso negado');
        }
    }
}
