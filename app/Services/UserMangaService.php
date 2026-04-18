<?php

namespace App\Services;

use App\Models\UserManga;
use Illuminate\Pagination\LengthAwarePaginator;

class UserMangaService
{
    public function getUserMangas(int $userId, array $filters = []): LengthAwarePaginator
    {
        $query = UserManga::with(['manga', 'site'])
            ->where('user_id', $userId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    public function create(int $userId, array $data): UserManga
    {
        $userManga = UserManga::create(array_merge($data, ['user_id' => $userId]));

        return $userManga->load(['manga', 'site']);
    }
}
