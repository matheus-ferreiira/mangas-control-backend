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

        $query = UserContent::with(['content', 'site', 'userSite'])
            ->where('user_id', $userId);

        // Filtro global de conteúdo adulto (perfil do usuário). Default: esconder +18.
        $showAdult = (bool) (optional(auth()->user())->show_adult_content ?? false);
        if (! $showAdult) {
            $query->whereHas('content', fn ($q) => $q->where('is_adult', false));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->whereHas('content', fn ($q) => $q->where('type', $filters['type']));
        }

        if (! empty($filters['content_id'])) {
            $query->where('content_id', (int) $filters['content_id']);
        }

        if (! empty($filters['user_site_id'])) {
            $query->where('user_site_id', (int) $filters['user_site_id']);
        }

        return $query->orderByDesc('updated_at')->paginate(9999);
    }

    /**
     * Itens da biblioteca cujo conteúdo no catálogo foi atualizado DEPOIS da última
     * interação do usuário, nos últimos 7 dias (novidades ainda não acompanhadas).
     */
    public function getWithUpdates(int $userId): \Illuminate\Support\Collection
    {
        $showAdult = (bool) (optional(auth()->user())->show_adult_content ?? false);

        return UserContent::query()
            ->with(['content', 'site', 'userSite'])
            ->join('contents', 'contents.id', '=', 'user_contents.content_id')
            ->where('user_contents.user_id', $userId)
            ->whereNotIn('user_contents.status', ['completed', 'dropped'])
            ->when(! $showAdult, fn ($q) => $q->where('contents.is_adult', false))
            ->where('contents.updated_at', '>=', now()->subDays(7))
            ->whereColumn('contents.updated_at', '>', 'user_contents.updated_at')
            ->orderByDesc('contents.updated_at')
            ->select('user_contents.*')
            ->limit(30)
            ->get();
    }

    public function create(int $userId, array $data): UserContent
    {
        $userContent = UserContent::create(array_merge($data, ['user_id' => $userId]));

        $userContent->load(['content', 'site', 'userSite']);

        LogHelper::info('Item adicionado à biblioteca', [
            'user_id' => $userId,
            'user_content_id' => $userContent->id,
            'content_id' => $userContent->content_id,
        ]);

        return $userContent;
    }
}
