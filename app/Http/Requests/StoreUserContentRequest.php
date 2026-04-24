<?php

namespace App\Http\Requests;

class StoreUserContentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content_id'    => ['required', 'integer', 'exists:contents,id'],
            'site_id'       => ['nullable', 'integer', 'exists:sites,id'],
            'current_units' => ['nullable', 'integer', 'min:0'],
            'rating'        => ['nullable', 'numeric', 'min:0', 'max:10'],
            'status'        => ['nullable', 'in:reading,completed,paused,dropped,plan_to_read'],
        ];
    }
}
