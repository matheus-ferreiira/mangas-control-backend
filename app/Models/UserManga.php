<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserManga extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'manga_id',
        'site_id',
        'current_chapters',
        'rating',
        'status',
    ];

    protected $casts = [
        'current_chapters' => 'integer',
        'rating' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manga(): BelongsTo
    {
        return $this->belongsTo(Manga::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
