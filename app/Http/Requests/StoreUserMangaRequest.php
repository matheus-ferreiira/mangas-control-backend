<?php

namespace App\Http\Requests;

class StoreUserMangaRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'manga_id' => 'required|exists:mangas,id',
            'site_id' => 'required|exists:sites,id',
            'current_chapters' => 'nullable|integer|min:0',
            'rating' => 'nullable|numeric|min:0|max:10',
            'status' => 'nullable|in:reading,completed,paused,dropped,plan_to_read',
        ];
    }
}
