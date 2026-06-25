<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'releases_api_url',
        'releases_api_type',
        'releases_title_field',
        'releases_chapter_field',
    ];

    public function userContents(): HasMany
    {
        return $this->hasMany(UserContent::class);
    }
}
