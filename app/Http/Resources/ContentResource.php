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
            // Identidade
            'id' => $this->id,
            'external_id' => $this->external_id,
            'source' => $this->source,

            // Nomes
            'name' => $this->name,
            'alternative_names' => $this->alternative_names ?? [],

            // Mídia
            'cover' => $this->resolveUrl($this->cover),
            'background' => $this->resolveUrl($this->background),
            'trailer_url' => $this->trailer_url,
            'trailer_embed_url' => $this->buildEmbedUrl($this->trailer_url),

            // Classificação
            'type' => $this->type,
            'origin_type' => $this->origin_type,
            'status' => $this->status,
            'is_adult' => $this->is_adult,
            'age_rating' => $this->age_rating,
            'is_in_library' => (bool) ($this->is_in_library ?? false),

            // Conteúdo
            'total_units' => $this->total_units,
            'total_seasons' => $this->total_seasons,
            'season_episodes' => $this->season_episodes ?? null,
            'duration' => $this->duration,
            'duration_formatted' => $this->formatDuration($this->duration),
            'last_unit_update' => $this->last_unit_update,
            'synopsis' => $this->synopsis,
            'tagline' => $this->tagline,
            'genres' => $this->genres ?? [],
            'studios' => $this->studios ?? [],
            'demographics' => $this->demographics ?? [],
            'themes' => $this->themes ?? [],
            'networks' => $this->networks ?? [],

            // Origem
            'release_year' => $this->release_year,
            'original_language' => $this->original_language,
            'country' => $this->country,

            // Métricas
            'rating' => $this->rating,
            'votes_count' => $this->votes_count,
            'popularity' => $this->popularity,
            'score' => $this->score,

            // Timestamps
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function resolveUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http')
            ? $path
            : Storage::disk('public')->url($path);
    }

    private function buildEmbedUrl(?string $trailerUrl): ?string
    {
        if (! $trailerUrl) {
            return null;
        }

        if (preg_match('/[?&]v=([^&]+)/', $trailerUrl, $matches)) {
            return 'https://www.youtube.com/embed/'.$matches[1];
        }

        return null;
    }

    private function formatDuration(?int $minutes): ?string
    {
        if (! $minutes || $minutes <= 0) {
            return null;
        }

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        if ($h > 0 && $m > 0) {
            return "{$h}h {$m}min";
        }

        return $h > 0 ? "{$h}h" : "{$m}min";
    }
}
