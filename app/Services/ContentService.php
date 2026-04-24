<?php

namespace App\Services;

use App\Models\Content;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentService
{
    public function getContents(array $filters): LengthAwarePaginator
    {
        $query = Content::query();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        return $query->orderBy('name')->paginate(15);
    }
}
