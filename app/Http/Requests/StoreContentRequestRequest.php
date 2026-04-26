<?php

namespace App\Http\Requests;

class StoreContentRequestRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:255'],
            'alternative_names'   => ['nullable', 'array'],
            'alternative_names.*' => ['string', 'max:255'],
            'type'                => ['required', 'in:manga,anime,novel'],
            'cover'               => ['nullable', 'string', 'max:500'],
        ];
    }
}
