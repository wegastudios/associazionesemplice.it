<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Topic extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('sort')->orderBy('name');
    }
}
