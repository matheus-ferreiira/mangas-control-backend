<?php

namespace App\Http\Controllers;

use App\Helpers\LogHelper;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $sites = Site::query()
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->paginate($request->integer('per_page', 9999));

        return $this->success(SiteResource::collection($sites)->response()->getData(true));
    }

    public function store(StoreSiteRequest $request): JsonResponse
    {
        $site = Site::create($request->validated());

        LogHelper::info('Site criado', [
            'site_id' => $site->id,
            'name'    => $site->name,
            'url'     => $site->url,
        ]);

        return $this->success(new SiteResource($site), 'Site criado com sucesso', 201);
    }

    public function show(Site $site): JsonResponse
    {
        return $this->success(new SiteResource($site));
    }

    public function update(UpdateSiteRequest $request, Site $site): JsonResponse
    {
        $site->update($request->validated());

        LogHelper::info('Site atualizado', [
            'site_id' => $site->id,
            'fields'  => array_keys($request->validated()),
        ]);

        return $this->success(new SiteResource($site), 'Site atualizado com sucesso');
    }

    public function destroy(Site $site): JsonResponse
    {
        $id   = $site->id;
        $name = $site->name;

        $site->delete();

        LogHelper::info('Site removido', [
            'site_id' => $id,
            'name'    => $name,
        ]);

        return $this->success(null, 'Site removido com sucesso');
    }
}
