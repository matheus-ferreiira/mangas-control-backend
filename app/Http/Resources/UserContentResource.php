<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\UserSiteResource;

class UserContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'user_id'          => $this->user_id,
            'content'          => new ContentResource($this->whenLoaded('content')),
            'site'             => new SiteResource($this->whenLoaded('site')),
            'user_site'        => new UserSiteResource($this->whenLoaded('userSite')),
            'current_units'    => $this->current_units,
            'last_unit_update' => $this->last_unit_update,
            'rating'           => $this->rating,
            'status'           => $this->status,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
