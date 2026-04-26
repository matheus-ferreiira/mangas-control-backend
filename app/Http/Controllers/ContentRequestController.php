<?php

namespace App\Http\Controllers;

use App\Helpers\LogHelper;
use App\Http\Requests\RejectContentRequestRequest;
use App\Http\Requests\StoreContentRequestRequest;
use App\Http\Resources\ContentRequestResource;
use App\Services\ContentRequestService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private ContentRequestService $contentRequestService) {}

    public function index(Request $request): JsonResponse
    {
        $requests = $this->contentRequestService->list(
            $request->only(['status', 'user_id'])
        );

        return $this->success(ContentRequestResource::collection($requests));
    }

    public function store(StoreContentRequestRequest $request): JsonResponse
    {
        $contentRequest = $this->contentRequestService->create(
            auth()->id(),
            $request->validated()
        );

        return $this->success(
            new ContentRequestResource($contentRequest),
            'Solicitação enviada com sucesso',
            201
        );
    }

    public function myRequests(Request $request): JsonResponse
    {
        $requests = $this->contentRequestService->list([
            'user_id' => auth()->id(),
            'status'  => $request->query('status'),
        ]);

        return $this->success(ContentRequestResource::collection($requests));
    }

    public function approve(int $id): JsonResponse
    {
        try {
            $contentRequest = $this->contentRequestService->approve($id, auth()->id());

            return $this->success(
                new ContentRequestResource($contentRequest),
                'Solicitação aprovada e conteúdo adicionado ao catálogo'
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 422);
        }
    }

    public function reject(RejectContentRequestRequest $request, int $id): JsonResponse
    {
        try {
            $contentRequest = $this->contentRequestService->reject(
                $id,
                auth()->id(),
                $request->validated()['rejection_reason']
            );

            return $this->success(
                new ContentRequestResource($contentRequest),
                'Solicitação rejeitada'
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 422);
        }
    }
}
