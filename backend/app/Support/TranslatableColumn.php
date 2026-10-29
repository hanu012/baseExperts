<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Search and sort callbacks for a Filament TextColumn over a translatable
 * (JSON) column.
 *
 * The stock ->searchable() runs LIKE against the raw stored string, which
 * contains the language keys themselves — so searching "en" or "hi"
 * matched every row. These target the per-language values instead.
 */
class TranslatableColumn
{
    public static function search(string $column): \Closure
    {
        return fn (Builder $query, string $search): Builder => $query->where(
            function (Builder $query) use ($column, $search) {
                foreach (array_keys(Languages::SUPPORTED) as $locale) {
                    $query->orWhere("{$column}->{$locale}", 'like', "%{$search}%");
                }
            }
        );
    }

    /** Sorted by the English value, which every row is required to have. */
    public static function sort(string $column): \Closure
    {
        return fn (Builder $query, string $direction): Builder => $query
            ->orderBy("{$column}->".Languages::ENGLISH, $direction);
    }
}
