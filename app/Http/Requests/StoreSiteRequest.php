<?php

namespace App\Http\Requests;

class StoreSiteRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:500',
        ];
    }
}
