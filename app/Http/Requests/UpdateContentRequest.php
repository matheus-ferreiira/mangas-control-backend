<?php

namespace App\Http\Requests;

class UpdateContentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'cover'       => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'type'        => ['sometimes', 'required', 'in:manga,anime,novel'],
            'total_units' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
