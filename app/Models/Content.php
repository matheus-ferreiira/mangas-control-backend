<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Content extends Model
{
    protected $fillable = ['name', 'cover', 'type', 'total_units'];

    protected $casts = [
        'total_units' => 'integer',
    ];

    public function userContents(): HasMany
    {
        return $this->hasMany(UserContent::class);
    }
}
