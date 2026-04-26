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
        'last_unit_update',
    ];

    protected $casts = [
        'total_units'       => 'integer',
        'alternative_names' => 'array',
        'last_unit_update'  => 'datetime',
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
