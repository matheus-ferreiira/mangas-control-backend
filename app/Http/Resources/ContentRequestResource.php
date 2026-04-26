<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'user'              => new UserResource($this->whenLoaded('user')),
            'name'              => $this->name,
            'alternative_names' => $this->alternative_names ?? [],
            'type'              => $this->type,
            'cover'             => $this->cover,
            'status'            => $this->status,
            'admin'             => new UserResource($this->whenLoaded('admin')),
            'rejection_reason'  => $this->rejection_reason,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
