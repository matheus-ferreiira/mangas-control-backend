<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'content'       => new ContentResource($this->whenLoaded('content')),
            'site'          => new SiteResource($this->whenLoaded('site')),
            'current_units' => $this->current_units,
            'rating'        => $this->rating,
            'status'        => $this->status,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
