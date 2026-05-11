<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Content extends Model
{
    protected $fillable = [
        'name',
        'alternative_names',
        'cover',
        'type',
        'status',
        'total_units',
        'total_seasons',
        'season_episodes',
        'last_unit_update',
        'rating',
        'popularity',
        'votes_count',
        'synopsis',
        'genres',
        'release_year',
        'original_language',
        'background',
        'external_id',
        'source',
        'score',
        'is_adult',
        'duration',
        'trailer_url',
        'country',
        'origin_type',
        'studios',
        'demographics',
        'themes',
        'tagline',
        'age_rating',
        'networks',
    ];

    protected $casts = [
        'total_units' => 'integer',
        'alternative_names' => 'array',
        'genres' => 'array',
        'studios' => 'array',
        'demographics' => 'array',
        'themes' => 'array',
        'networks' => 'array',
        'last_unit_update' => 'datetime',
        'rating' => 'float',
        'score' => 'float',
        'popularity' => 'integer',
        'votes_count' => 'integer',
        'release_year' => 'integer',
        'total_seasons' => 'integer',
        'season_episodes' => 'array',
        'duration' => 'integer',
        'is_adult' => 'boolean',
    ];

    public function userContents(): HasMany
    {
        return $this->hasMany(UserContent::class);
    }

    public function contentRequests(): HasMany
    {
        return $this->hasMany(ContentRequest::class);
    }
}
