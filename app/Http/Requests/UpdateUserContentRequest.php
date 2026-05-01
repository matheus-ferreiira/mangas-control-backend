<?php

namespace App\Http\Requests;

use App\Models\UserContent;
use Illuminate\Validation\Rule;

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
            'user_site_id'  => [
                'nullable',
                'integer',
                Rule::exists('user_sites', 'id')->where('user_id', auth()->id()),
            ],
            'current_units' => [
                'sometimes',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) {
                    if ($value === null) {
                        return;
                    }

                    $userContent = UserContent::with('content')->find($this->route('id'));
                    $total       = $userContent?->content?->total_units;

                    if ($total !== null && $value > $total) {
                        $fail("Você não pode ultrapassar o total de episódios/capítulos ({$total}).");
                    }
                },
            ],
            'rating'        => ['nullable', 'numeric', 'min:0', 'max:10'],
            'status'        => ['sometimes', 'in:reading,completed,paused,dropped,plan_to_read'],
        ];
    }
}
