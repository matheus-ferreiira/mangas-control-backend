<?php

namespace App\Services;

use App\Helpers\LogHelper;
use App\Helpers\NameHelper;
use App\Models\Content;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ContentService
{
    private const SORTABLE       = ['updated_at', 'created_at', 'name', 'rating', 'popularity', 'votes_count', 'score', 'release_year'];
    private const SORT_NULL_LAST = ['rating', 'popularity', 'votes_count', 'score'];
    private const DEFAULT_SORT   = 'popularity';
    private const MAX_PER_PAGE   = 100;

    public function getContents(array $filters, ?int $userId = null): LengthAwarePaginator
    {
        LogHelper::debug('Listagem de conteúdos', ['filters' => $filters]);

        $query = Content::query();

        // Subquery de biblioteca: campo calculado is_in_library por usuário autenticado
        if ($userId) {
            $query->selectRaw(
                'contents.*, EXISTS(SELECT 1 FROM user_contents WHERE content_id = contents.id AND user_id = ?) as is_in_library',
                [(int) $userId]
            );
        }

        // ── Filtros básicos ───────────────────────────────────────────────────

        if (! empty($filters['type'])) {
            $query->whereIn('type', (array) $filters['type']);
        }

        if (! empty($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        if (isset($filters['is_adult']) && $filters['is_adult'] !== '') {
            $query->where('is_adult', filter_var($filters['is_adult'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['language'])) {
            $query->where('original_language', $filters['language']);
        }

        if (! empty($filters['country'])) {
            $query->where('country', $filters['country']);
        }

        // ── Filtros de ano ────────────────────────────────────────────────────

        if (! empty($filters['year'])) {
            // Compatibilidade com clientes antigos — ano exato
            $query->where('release_year', (int) $filters['year']);
        } else {
            if (! empty($filters['year_min'])) {
                $query->where('release_year', '>=', (int) $filters['year_min']);
            }

            if (! empty($filters['year_max'])) {
                $query->where('release_year', '<=', (int) $filters['year_max']);
            }
        }

        // ── Filtros de métricas ───────────────────────────────────────────────

        if (! empty($filters['rating_min'])) {
            $query->where('rating', '>=', (float) $filters['rating_min']);
        }

        if (! empty($filters['rating_max'])) {
            $query->where('rating', '<=', (float) $filters['rating_max']);
        }

        if (! empty($filters['votes_min'])) {
            $query->where('votes_count', '>=', (int) $filters['votes_min']);
        }

        // ── Filtro de gêneros (OR entre múltiplos) ────────────────────────────

        if (! empty($filters['genres'])) {
            $genres = (array) $filters['genres'];
            $query->where(function ($q) use ($genres) {
                foreach ($genres as $genre) {
                    $q->orWhereJsonContains('genres', $genre);
                }
            });
        }

        // ── Busca em nome, alternative_names e synopsis ───────────────────────

        $searchTerm = null;

        if (! empty($filters['search'])) {
            $searchTerm = NameHelper::normalize($filters['search']);
            $query->where(function ($q) use ($searchTerm) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"])
                  ->orWhereRaw("JSON_SEARCH(LOWER(alternative_names), 'one', ?) IS NOT NULL", ["%{$searchTerm}%"])
                  ->orWhereRaw('LOWER(synopsis) LIKE ?', ["%{$searchTerm}%"]);
            });
        }

        if (! empty($filters['recent'])) {
            $query->where('created_at', '>=', now()->subDays(5));
        }

        // ── Ordenação: relevância quando há busca; critério normal caso contrário ──

        if ($searchTerm) {
            // Prioridade: começa com o termo → contém → alt names → synopsis
            $query->orderByRaw(
                "CASE
                    WHEN LOWER(name) LIKE ?           THEN 1
                    WHEN LOWER(name) LIKE ?            THEN 2
                    WHEN JSON_SEARCH(LOWER(alternative_names), 'one', ?) IS NOT NULL THEN 3
                    ELSE 4
                END, COALESCE(score, 0) DESC",
                ["{$searchTerm}%", "%{$searchTerm}%", "%{$searchTerm}%"]
            );
        } else {
            $sort  = in_array($filters['sort'] ?? '', self::SORTABLE) ? $filters['sort'] : self::DEFAULT_SORT;
            $order = ($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

            if (in_array($sort, self::SORT_NULL_LAST)) {
                $query->orderByRaw("CASE WHEN {$sort} IS NULL THEN 1 ELSE 0 END, {$sort} {$order}");
            } else {
                $query->orderBy($sort, $order);
            }
        }

        // ── Paginação ─────────────────────────────────────────────────────────

        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), self::MAX_PER_PAGE));

        return $query->paginate($perPage);
    }

    public function isDuplicate(string $name, ?array $alternativeNames = [], ?int $excludeId = null): bool
    {
        $query = Content::query();

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ((clone $query)->whereRaw('LOWER(TRIM(name)) = ?', [NameHelper::normalize($name)])->exists()) {
            return true;
        }

        foreach ($alternativeNames ?? [] as $checkName) {
            $check = NameHelper::normalize($checkName);
            if (! $check) {
                continue;
            }

            $altExists = (clone $query)
                ->where(function ($q) use ($check) {
                    $q->whereRaw('LOWER(TRIM(name)) = ?', [$check])
                      ->orWhereRaw("JSON_SEARCH(LOWER(alternative_names), 'one', ?) IS NOT NULL", [$check]);
                })
                ->exists();

            if ($altExists) {
                return true;
            }
        }

        return false;
    }
}
