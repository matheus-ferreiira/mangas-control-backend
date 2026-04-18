<?php

namespace App\Http\Requests;

class UpdateMangaRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'cover' => 'nullable|string|max:500',
            'total_chapters' => 'nullable|integer|min:1',
        ];
    }
}
