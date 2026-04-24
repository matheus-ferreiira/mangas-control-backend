<?php

namespace App\Services;

use App\Models\UserContent;
use Illuminate\Pagination\LengthAwarePaginator;

class UserContentService
{
    public function getUserContents(int $userId, array $filters): LengthAwarePaginator
    {
        $query = UserContent::with(['content', 'site'])
            ->where('user_id', $userId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $query->whereHas('content', fn ($q) => $q->where('type', $filters['type']));
        }

        return $query->orderByDesc('updated_at')->paginate(15);
    }

    public function create(int $userId, array $data): UserContent
    {
        $userContent = UserContent::create(array_merge($data, ['user_id' => $userId]));

        return $userContent->load(['content', 'site']);
    }
}
