<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'content' => new ContentResource($this->whenLoaded('content')),
            'site' => new SiteResource($this->whenLoaded('site')),
            'user_site' => new UserSiteResource($this->whenLoaded('userSite')),
            'current_units' => $this->current_units,
            'current_season' => $this->current_season ?? 1,
            'progress_percent' => $this->computeProgressPercent(),
            'last_unit_update' => $this->last_unit_update,
            'rating' => $this->rating,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function computeProgressPercent(): ?float
    {
        $current = $this->current_units;
        $content = $this->resource->relationLoaded('content') ? $this->resource->content : null;

        if ($content?->type === 'tv' && ! empty($content->season_episodes)) {
            $season = (string) ($this->current_season ?? 1);
            $total = $content->season_episodes[$season] ?? $content->total_units ?? null;
        } else {
            $total = $content?->total_units ?? null;
        }

        if ($current === null || ! $total || $total <= 0) {
            return null;
        }

        return round(min(($current / $total) * 100, 100), 1);
    }
}
