<?php

namespace App\Services;

use App\Helpers\LogHelper;
use App\Models\UserContent;
use Illuminate\Pagination\LengthAwarePaginator;

class UserContentService
{
    public function getUserContents(int $userId, array $filters): LengthAwarePaginator
    {
        LogHelper::debug('Listagem da biblioteca do usuário', [
            'user_id' => $userId,
            'filters' => $filters,
        ]);

        $query = UserContent::with(['content', 'site'])
            ->where('user_id', $userId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $query->whereHas('content', fn ($q) => $q->where('type', $filters['type']));
        }

        return $query->orderByDesc('updated_at')->paginate(9999);
    }

    public function create(int $userId, array $data): UserContent
    {
        $userContent = UserContent::create(array_merge($data, ['user_id' => $userId]));

        $userContent->load(['content', 'site']);

        LogHelper::info('Item adicionado à biblioteca', [
            'user_id'         => $userId,
            'user_content_id' => $userContent->id,
            'content_id'      => $userContent->content_id,
        ]);

        return $userContent;
    }
}
