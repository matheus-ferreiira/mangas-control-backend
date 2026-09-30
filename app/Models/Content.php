<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Content extends Model
{
    protected $fillable = [
        // Identificadores
        'anilist_id',
        'mal_id',
        'external_id',
        'source',
        // Nomes
        'name',
        'alternative_names',
        // Mídia
        'cover',
        'banner_image',
        'trailer_url',
        // Classificação / origem
        'type',
        'format',
        'origin_type',
        'origin_source',
        'status',
        'is_adult',
        'age_rating',
        // Conteúdo
        'total_units',
        'total_seasons',
        'season_episodes',
        'duration',
        'release_date',
        'end_date',
        'synopsis',
        'tagline',
        'genres',
        'studios',
        'demographics',
        'themes',
        'networks',
        // Origem
        'release_year',
        'original_language',
        'country',
        // Métricas
        'rating',
        'popularity',
        'votes_count',
        'score',
        'mal_rank',
        'mal_popularity_rank',
    ];

    protected $casts = [
        'anilist_id' => 'integer',
        'mal_id' => 'integer',
        'total_units' => 'integer',
        'alternative_names' => 'array',
        'genres' => 'array',
        'studios' => 'array',
        'demographics' => 'array',
        'themes' => 'array',
        'networks' => 'array',
        'release_date' => 'datetime',
        'end_date' => 'date',
        'rating' => 'float',
        'score' => 'float',
        'popularity' => 'integer',
        'votes_count' => 'integer',
        'mal_rank' => 'integer',
        'mal_popularity_rank' => 'integer',
        'release_year' => 'integer',
        'total_seasons' => 'integer',
        'season_episodes' => 'array',
        'duration' => 'integer',
        'is_adult' => 'boolean',
    ];

    /**
     * Filtro +18, único e vindo só do perfil (`users.show_adult_content`):
     * desligado (padrão) → só conteúdo não adulto; ligado → só conteúdo adulto.
     */
    public function scopeForAudience(Builder $query, ?User $user = null): Builder
    {
        return $query->where($query->qualifyColumn('is_adult'), self::adultModeFor($user));
    }

    /** Se o usuário (ou o autenticado) está no modo +18. */
    public static function adultModeFor(?User $user = null): bool
    {
        return (bool) (($user ?? auth()->user())?->show_adult_content ?? false);
    }

    public function userContents(): HasMany
    {
        return $this->hasMany(UserContent::class);
    }
}
