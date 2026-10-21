<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class Slug
{
    /** Slug univoco nella query data (aggiunge -2, -3... in caso di omonimia). */
    public static function unique(Builder $query, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'voce';
        $slug = $base;
        $i = 2;

        while ((clone $query)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
