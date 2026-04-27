<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'alternative_names' => $this->alternative_names ?? [],
            'cover' => $this->cover
                ? (str_starts_with($this->cover, 'http')
                    ? $this->cover
                    : Storage::disk('public')->url($this->cover))
            : null,
            'type'              => $this->type,
            'status'            => $this->status,
            'total_units'       => $this->total_units,
            'last_unit_update'  => $this->last_unit_update,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
