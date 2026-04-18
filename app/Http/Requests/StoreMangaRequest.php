<?php

namespace App\Http\Requests;

class StoreMangaRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'cover' => 'nullable|string|max:500',
            'total_chapters' => 'nullable|integer|min:1',
        ];
    }
}
