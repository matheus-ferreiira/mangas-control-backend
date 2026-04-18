<?php

namespace App\Http\Requests;

class UpdateUserMangaRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'manga_id' => 'sometimes|exists:mangas,id',
            'site_id' => 'sometimes|exists:sites,id',
            'current_chapters' => 'sometimes|integer|min:0',
            'rating' => 'nullable|numeric|min:0|max:10',
            'status' => 'sometimes|in:reading,completed,paused,dropped,plan_to_read',
        ];
    }
}
