<?php

namespace App\Http\Requests;

class UpdateUserContentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_id'       => ['nullable', 'integer', 'exists:sites,id'],
            'current_units' => ['sometimes', 'integer', 'min:0'],
            'rating'        => ['nullable', 'numeric', 'min:0', 'max:10'],
            'status'        => ['sometimes', 'in:reading,completed,paused,dropped,plan_to_read'],
        ];
    }
}
