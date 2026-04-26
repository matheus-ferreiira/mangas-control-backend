<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentRequest extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'alternative_names',
        'type',
        'cover',
        'status',
        'admin_id',
        'rejection_reason',
    ];

    protected $casts = [
        'alternative_names' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
