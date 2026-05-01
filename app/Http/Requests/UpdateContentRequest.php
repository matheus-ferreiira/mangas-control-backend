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
            'name'                => ['sometimes', 'required', 'string', 'max:255'],
            'alternative_names'   => ['nullable', 'array'],
            'alternative_names.*' => ['string', 'max:255'],
            'cover'               => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'type'                => ['sometimes', 'required', 'in:manga,anime,novel,movie,tv'],
            'status'              => ['nullable', 'in:ongoing,completed,hiatus,cancelled'],
            'total_units'         => ['nullable', 'integer', 'min:1'],
            'synopsis'            => ['nullable', 'string'],
            'genres'              => ['nullable', 'array'],
            'genres.*'            => ['string', 'max:100'],
            'release_year'        => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'original_language'   => ['nullable', 'string', 'max:10'],
        ];
    }
}
