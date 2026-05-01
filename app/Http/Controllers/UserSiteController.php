<?php

namespace App\Http\Controllers;

use App\Helpers\LogHelper;
use App\Http\Requests\StoreUserSiteRequest;
use App\Http\Requests\UpdateUserSiteRequest;
use App\Http\Resources\UserSiteResource;
use App\Models\UserSite;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSiteController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $sites = UserSite::where('user_id', auth()->id())
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->boolean('favorites'), fn ($q) => $q->where('is_favorite', true))
            ->orderByDesc('is_favorite')
            ->orderBy('name')
            ->get();

        return $this->success(UserSiteResource::collection($sites));
    }

    public function store(StoreUserSiteRequest $request): JsonResponse
    {
        $site = UserSite::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        LogHelper::info('User site criado', [
            'user_site_id' => $site->id,
            'user_id'      => auth()->id(),
            'name'         => $site->name,
            'url'          => $site->url,
        ]);

        return $this->success(new UserSiteResource($site), 'Site criado com sucesso', 201);
    }

    public function show(int $id): JsonResponse
    {
        $site = UserSite::where('user_id', auth()->id())->find($id);

        if (! $site) {
            return $this->error('Site não encontrado', [], 404);
        }

        return $this->success(new UserSiteResource($site));
    }

    public function update(UpdateUserSiteRequest $request, int $user_site): JsonResponse
    {
        $site = UserSite::where('user_id', auth()->id())->find($user_site);

        if (! $site) {
            return $this->error('Site não encontrado', [], 404);
        }

        $site->update($request->validated());

        LogHelper::info('User site atualizado', [
            'user_site_id' => $site->id,
            'user_id'      => auth()->id(),
            'fields'       => array_keys($request->validated()),
        ]);

        return $this->success(new UserSiteResource($site), 'Site atualizado com sucesso');
    }

    public function destroy(int $id): JsonResponse
    {
        $site = UserSite::where('user_id', auth()->id())->find($id);

        if (! $site) {
            return $this->error('Site não encontrado', [], 404);
        }

        $siteId   = $site->id;
        $siteName = $site->name;

        $site->delete();

        LogHelper::info('User site removido', [
            'user_site_id' => $siteId,
            'user_id'      => auth()->id(),
            'name'         => $siteName,
        ]);

        return $this->success(null, 'Site removido com sucesso');
    }
}
