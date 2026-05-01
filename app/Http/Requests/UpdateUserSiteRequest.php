<?php

namespace App\Http\Requests;

use App\Models\UserSite;

class UpdateUserSiteRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:255'],
            'url'         => ['sometimes', 'url', 'max:500'],
            'is_favorite' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (! $this->has('url')) {
                return;
            }

            $siteId = $this->route('user_site');

            $exists = UserSite::where('user_id', auth()->id())
                ->where('url', $this->url)
                ->where('id', '!=', $siteId)
                ->exists();

            if ($exists) {
                $v->errors()->add('url', 'Você já possui um site com esta URL.');
            }
        });
    }
}
