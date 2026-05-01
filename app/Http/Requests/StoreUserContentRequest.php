<?php

namespace App\Http\Requests;

use App\Models\Content;
use Illuminate\Validation\Rule;

class StoreUserContentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content_id' => [
                'required',
                'integer',
                'exists:contents,id',
                Rule::unique('user_contents', 'content_id')
                    ->where('user_id', auth()->id()),
            ],
            'site_id'       => ['nullable', 'integer', 'exists:sites,id'],
            'user_site_id'  => [
                'nullable',
                'integer',
                Rule::exists('user_sites', 'id')->where('user_id', auth()->id()),
            ],
            'current_units' => [
                'nullable',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) {
                    if ($value === null) {
                        return;
                    }

                    $content = Content::find($this->input('content_id'));

                    if ($content && $content->total_units !== null && $value > $content->total_units) {
                        $fail("Você não pode ultrapassar o total de episódios/capítulos ({$content->total_units}).");
                    }
                },
            ],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'status' => ['nullable', 'in:reading,completed,paused,dropped,plan_to_read'],
        ];
    }

    public function messages(): array
    {
        return [
            'content_id.unique' => 'Este conteúdo já está na sua biblioteca.',
        ];
    }
}
