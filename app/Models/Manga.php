<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Manga extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'cover', 'total_chapters'];

    public function userMangas(): HasMany
    {
        return $this->hasMany(UserManga::class);
    }
}
