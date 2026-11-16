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

    /** Argomenti pubblici; con le guide del gestionale spente restano fuori quelle del gestionale (salvo per lo staff). */
    public function scopeForPublic($query)
    {
        $query->where('is_public', true);

        return \App\Support\SaasGuides::visible() ? $query : $query->where(fn ($q) => $q->whereNull('product')->orWhere('product', '!=', 'saas'));
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('sort')->orderBy('name');
    }
}
