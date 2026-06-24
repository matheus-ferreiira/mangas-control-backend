<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'url'];

    public function userContents(): HasMany
    {
        return $this->hasMany(UserContent::class);
    }
}
