<?php

namespace App\Http\Requests;

use App\Models\UserSite;

class StoreUserSiteRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'url'         => ['required', 'url', 'max:500'],
            'is_favorite' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $exists = UserSite::where('user_id', auth()->id())
                ->where('url', $this->url)
                ->exists();

            if ($exists) {
                $v->errors()->add('url', 'Você já possui um site com esta URL.');
            }
        });
    }
}
