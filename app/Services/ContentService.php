<?php

namespace App\Services;

use App\Helpers\LogHelper;
use App\Models\Content;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentService
{
    public function getContents(array $filters): LengthAwarePaginator
    {
        LogHelper::debug('Listagem de conteúdos', ['filters' => $filters]);

        $query = Content::query();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', '%'.$term.'%')
                  ->orWhereJsonContains('alternative_names', $term)
                  ->orWhereRaw("JSON_SEARCH(alternative_names, 'one', ?) IS NOT NULL", ['%'.$term.'%']);
            });
        }

        if (!empty($filters['recent'])) {
            $query->where('created_at', '>=', now()->subDays(5));
        }

        return $query->orderBy('name')->paginate(9999);
    }

    public function isDuplicate(string $name, ?array $alternativeNames = [], ?int $excludeId = null): bool
    {
        $query = Content::query();

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $nameExists = (clone $query)->where('name', $name)->exists();

        if ($nameExists) {
            return true;
        }

        $allNames = array_merge([$name], $alternativeNames ?? []);

        foreach ($allNames as $checkName) {
            $altExists = (clone $query)
                ->where('name', $checkName)
                ->orWhereJsonContains('alternative_names', $checkName)
                ->exists();

            if ($altExists) {
                return true;
            }
        }

        return false;
    }
}
