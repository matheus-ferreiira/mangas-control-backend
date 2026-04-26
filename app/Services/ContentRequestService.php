<?php

namespace App\Services;

use App\Helpers\LogHelper;
use App\Models\Content;
use App\Models\ContentRequest;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentRequestService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = ContentRequest::with(['user', 'admin']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->orderByDesc('created_at')->paginate(9999);
    }

    public function create(int $userId, array $data): ContentRequest
    {
        $contentRequest = ContentRequest::create(array_merge($data, [
            'user_id' => $userId,
            'status'  => 'pending',
        ]));

        $contentRequest->load(['user']);

        LogHelper::info('Solicitação de conteúdo criada', [
            'content_request_id' => $contentRequest->id,
            'user_id'            => $userId,
            'name'               => $contentRequest->name,
        ]);

        return $contentRequest;
    }

    public function approve(int $requestId, int $adminId): ContentRequest
    {
        $contentRequest = ContentRequest::findOrFail($requestId);

        if ($contentRequest->status !== 'pending') {
            throw new \Exception('Solicitação já foi processada');
        }

        $content = Content::create([
            'name'              => $contentRequest->name,
            'alternative_names' => $contentRequest->alternative_names,
            'type'              => $contentRequest->type,
            'cover'             => $contentRequest->cover,
            'status'            => 'ongoing',
        ]);

        $contentRequest->update([
            'status'   => 'approved',
            'admin_id' => $adminId,
        ]);

        $contentRequest->load(['user', 'admin']);

        LogHelper::info('Solicitação aprovada', [
            'content_request_id' => $contentRequest->id,
            'content_id'         => $content->id,
            'admin_id'           => $adminId,
        ]);

        return $contentRequest;
    }

    public function reject(int $requestId, int $adminId, string $reason): ContentRequest
    {
        $contentRequest = ContentRequest::findOrFail($requestId);

        if ($contentRequest->status !== 'pending') {
            throw new \Exception('Solicitação já foi processada');
        }

        $contentRequest->update([
            'status'           => 'rejected',
            'admin_id'         => $adminId,
            'rejection_reason' => $reason,
        ]);

        $contentRequest->load(['user', 'admin']);

        LogHelper::info('Solicitação rejeitada', [
            'content_request_id' => $contentRequest->id,
            'admin_id'           => $adminId,
            'reason'             => $reason,
        ]);

        return $contentRequest;
    }
}
