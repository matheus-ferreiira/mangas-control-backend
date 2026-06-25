<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserContent extends Model
{
    protected $fillable = [
        'user_id',
        'content_id',
        'site_id',
        'user_site_id',
        'site_title',
        'site_last_chapter',
        'current_units',
        'current_season',
        'last_unit_update',
        'rating',
        'status',
    ];

    protected $casts = [
        'current_units' => 'integer',
        'current_season' => 'integer',
        'rating' => 'float',
        'last_unit_update' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function userSite(): BelongsTo
    {
        return $this->belongsTo(UserSite::class);
    }
}
