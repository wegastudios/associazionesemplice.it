<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $guarded = [];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class)->orderBy('title');
    }
}
